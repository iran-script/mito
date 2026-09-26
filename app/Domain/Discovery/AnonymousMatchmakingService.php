<?php

namespace App\Domain\Discovery;

use App\Domain\Chat\ConversationService;
use App\Domain\Chat\ConversationStatus;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Facades\DB;

class AnonymousMatchmakingService
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function attempt(int $userId, int $generation): ?array
    {
        return DB::transaction(function () use ($userId, $generation) {
            $mine = DB::table('matchmaking_searches')->where('user_id', $userId)->lockForUpdate()->first();
            if (! $mine || $mine->status !== 'waiting' || (int) $mine->generation !== $generation || now()->gte($mine->expires_at)) {
                return null;
            }
            $myGender = DB::table('profiles')->where('user_id', $userId)->value('gender');
            $candidateId = DB::table('matchmaking_searches as m')
                ->join('profiles as p', 'p.user_id', '=', 'm.user_id')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.user_id', '<>', $userId)->where('m.status', 'waiting')->where('m.expires_at', '>', now())
                ->where('m.gender', $myGender)->where('p.gender', $mine->gender)
                ->where('p.status', ProfileStatus::Active->value)->where('u.status', UserStatus::Active->value)
                ->whereNotExists(fn ($q) => $q->from('user_blocks')->whereColumn('blocker_user_id', 'm.user_id')->where('blocked_user_id', $userId))
                ->whereNotExists(fn ($q) => $q->from('user_blocks')->where('blocker_user_id', $userId)->whereColumn('blocked_user_id', 'm.user_id'))
                ->whereNotExists(fn ($q) => $q->from('conversation_participants as cp')->join('conversations as c', 'c.id', '=', 'cp.conversation_id')->whereColumn('cp.user_id', 'm.user_id')->where('c.status', ConversationStatus::Active->value))
                ->orderBy('m.started_at')->value('m.user_id');
            if (! $candidateId) {
                return null;
            }
            $candidate = DB::table('matchmaking_searches')->where('user_id', $candidateId)->lockForUpdate()->first();
            $mine = DB::table('matchmaking_searches')->where('user_id', $userId)->lockForUpdate()->first();
            if (! $candidate || $candidate->status !== 'waiting' || now()->gte($candidate->expires_at) || $mine->status !== 'waiting') {
                return null;
            }
            $busy = DB::table('conversation_participants as cp')->join('conversations as c', 'c.id', '=', 'cp.conversation_id')->whereIn('cp.user_id', [$userId, $candidateId])->where('c.status', ConversationStatus::Active->value)->exists();
            if ($busy) {
                return null;
            }
            $conversation = $this->conversations->connectAnonymous($userId, (int) $candidateId);
            DB::table('matchmaking_searches')->where('user_id', $userId)->where('status', 'waiting')->update(['status' => 'matched', 'matched_user_id' => $candidateId, 'updated_at' => now()]);
            DB::table('matchmaking_searches')->where('user_id', $candidateId)->where('status', 'waiting')->update(['status' => 'matched', 'matched_user_id' => $userId, 'updated_at' => now()]);

            return ['first' => $userId, 'second' => (int) $candidateId, 'first_generation' => $generation, 'second_generation' => (int) $candidate->generation, 'conversation_id' => $conversation->id];
        }, 3);
    }
}
