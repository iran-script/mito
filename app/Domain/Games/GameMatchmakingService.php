<?php

namespace App\Domain\Games;

use App\Domain\Profiles\ProfileStatus;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GameMatchmakingService
{
    // A two-key namespace separate from Telegram sender locks. Matching must serialize
    // across BOTH game types, including first inserts when no user queue row exists.
    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [1296651343, 1]);
    }

    public function search(User $user, GameType $type, string $gender): array
    {
        if (! in_array($type, [GameType::RockPaperScissors, GameType::TruthOrDare], true) || ! in_array($gender, ['male', 'female'], true)) {
            throw new \DomainException('That game is unavailable.');
        }

        return DB::transaction(function () use ($user, $type, $gender) {
            $this->lock();
            $user = $user->fresh('profile');
            if ($user->status !== UserStatus::Active || $user->profile?->status !== ProfileStatus::Active) {
                throw new \DomainException('This player cannot be invited.');
            }
            app(GameAvailability::class)->assertEnabled($type);
            $live = $this->liveSession($user->id);
            if ($live) {
                DB::table('game_matchmaking_queue')->where('user_id', $user->id)->where('status', 'waiting')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);

                return ['matched' => true, 'existing' => true, 'session_id' => $live->id];
            }
            $now = now();
            $row = DB::table('game_matchmaking_queue')->where('user_id', $user->id)->where('status', 'waiting')->lockForUpdate()->first();
            if ($row && CarbonImmutable::parse($row->expires_at)->lte($now)) {
                DB::table('game_matchmaking_queue')->where('id', $row->id)->update(['status' => 'expired', 'updated_at' => $now]);
                $row = null;
            }
            if ($row && ($row->game_type !== $type->value || $row->desired_gender !== $gender)) {
                DB::table('game_matchmaking_queue')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => $now]);
                $row = null;
            }
            if (! $row) {
                $id = DB::table('game_matchmaking_queue')->insertGetId([
                    'user_id' => $user->id, 'game_type' => $type->value, 'desired_gender' => $gender,
                    'status' => 'waiting', 'started_at' => $now,
                    'expires_at' => $now->copy()->addSeconds((int) config('discovery.matchmaking_timeout_seconds', 120)),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $row = DB::table('game_matchmaking_queue')->where('id', $id)->first();
            }
            $candidates = DB::table('game_matchmaking_queue')->where('status', 'waiting')->where('game_type', $type->value)
                ->where('user_id', '<>', $user->id)->orderBy('started_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($candidates as $candidate) {
                $other = User::with('profile')->find($candidate->user_id);
                if (CarbonImmutable::parse($candidate->expires_at)->lte($now) || ! $other || $other->status !== UserStatus::Active || $other->profile?->status !== ProfileStatus::Active) {
                    DB::table('game_matchmaking_queue')->where('id', $candidate->id)->update(['status' => 'expired', 'updated_at' => $now]);

                    continue;
                }
                if ($this->liveSession($other->id)) {
                    DB::table('game_matchmaking_queue')->where('id', $candidate->id)->update(['status' => 'cancelled', 'updated_at' => $now]);

                    continue;
                }
                if ($candidate->desired_gender !== $user->profile->gender?->value || $other->profile->gender?->value !== $gender || app(BlockService::class)->isBlocked($user, $other)) {
                    continue;
                }
                $session = GameSession::create(['game_type' => $type, 'origin' => 'random_matchmaking', 'status' => GameStatus::Active,
                    'created_by' => $user->id, 'current_round' => 1, 'expires_at' => now()->addMinutes(30), 'state' => ['started_at' => $now->toIso8601String()]]);
                $session->participants()->attach([$user->id, $other->id]);
                DB::table('game_matchmaking_queue')->whereIn('id', [$row->id, $candidate->id])->update([
                    'status' => 'matched', 'matched_at' => $now, 'matched_session_id' => $session->id, 'updated_at' => $now,
                ]);
                app(GameService::class)->ensureRound($session);
                app(SocialNotificationService::class)->queue($other, 'game_matched', $session->id,
                    __('🎉 حریف پیدا شد!')."\n".__('🎮 بازی شروع شد.'), 'game_matched:'.$session->id.':'.$other->id,
                    [[['text' => __('🎮 بازی'), 'callback_data' => 'd:0:game_open_'.$session->id]]]);

                return ['matched' => true, 'existing' => false, 'session_id' => $session->id];
            }

            return ['matched' => false, 'queue_id' => $row->id];
        }, 3);
    }

    public function cancel(User $user, ?int $id = null): bool
    {
        return DB::transaction(function () use ($user, $id) {
            $this->lock();

            return DB::table('game_matchmaking_queue')->where('user_id', $user->id)->where('status', 'waiting')
                ->when($id !== null, fn ($q) => $q->where('id', $id))->update(['status' => 'cancelled', 'updated_at' => now()]) > 0;
        });
    }

    public function expire(int $id): ?object
    {
        return DB::transaction(function () use ($id) {
            $this->lock();
            $row = DB::table('game_matchmaking_queue')->where('id', $id)->where('status', 'waiting')->where('expires_at', '<=', now())->lockForUpdate()->first();
            if ($row) {
                DB::table('game_matchmaking_queue')->where('id', $id)->update(['status' => 'expired', 'updated_at' => now()]);
            }

            return $row;
        });
    }

    public function liveSession(int $userId): ?GameSession
    {
        return GameSession::whereIn('status', [GameStatus::Waiting, GameStatus::Accepted, GameStatus::Active])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('participants', fn ($q) => $q->whereKey($userId))->first();
    }
}
