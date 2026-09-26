<?php
namespace App\Domain\Telegram;
use App\Domain\Chat\ConversationService;
use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class InteractionStateResolver
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function resolve(User $user, InteractionState $state): array
    {
        $active = $this->conversations->activeFor($user);
        $reason = $this->invalidReason($user, $state, $active);
        if ($reason === null) return ['recovered' => false, 'reason' => null, 'active_conversation_id' => $active?->id];
        $oldMode = $state->mode;
        $state->forceFill([
            'mode' => $active ? 'chat' : 'menu',
            'conversation_id' => $active?->id,
            'direct_recipient_id' => null,
            'bulk_mode' => null, 'bulk_selection' => null, 'bulk_context' => null,
            'event_context' => null, 'game_context' => null, 'direct_context' => null,
            'expires_at' => null,
        ])->save();
        Log::warning('stale_state_recovered', [
            'user_id' => $user->id, 'public_mito_id' => $user->public_mito_id,
            'state' => $oldMode, 'reason' => $reason, 'active_conversation_id' => $active?->id,
        ]);
        return ['recovered' => true, 'reason' => $reason, 'active_conversation_id' => $active?->id];
    }

    public function recover(User $user, ?InteractionState $state = null): InteractionState
    {
        $state ??= InteractionState::firstOrCreate(['user_id' => $user->id]);
        $active = $this->conversations->activeFor($user);
        $state->forceFill(['mode' => $active ? 'chat' : 'menu', 'conversation_id' => $active?->id,
            'direct_recipient_id' => null, 'bulk_mode' => null, 'bulk_selection' => null,
            'bulk_context' => null, 'event_context' => null, 'game_context' => null,
            'direct_context' => null, 'expires_at' => null])->save();
        Log::warning('recovery_fallback', ['user_id' => $user->id, 'state' => $state->mode, 'active_conversation_id' => $active?->id]);
        return $state->fresh();
    }

    public function cleanupStaleStates(): int
    {
        $count = 0;
        InteractionState::query()->chunkById(100, function ($states) use (&$count): void {
            foreach ($states as $state) $count += $this->resolve($state->user, $state)['recovered'] ? 1 : 0;
        });
        return $count;
    }

    public static function expiryForMode(?string $mode): ?\DateTimeInterface
    {
        if ($mode === null || in_array($mode, ['menu', 'chat'], true)) return null;
        $minutes = match (true) {
            in_array($mode, ['direct', 'direct_compose', 'direct_review'], true) => 15,
            str_starts_with($mode, 'profile_edit_') => 30,
            in_array($mode, ['search_male', 'search_female'], true) => 3,
            str_starts_with($mode, 'game_') => 30,
            $mode === 'event_create' => 60,
            default => 30,
        };
        return now()->addMinutes($minutes);
    }

    private function invalidReason(User $user, InteractionState $state, ?object $active): ?string
    {
        if ($state->expires_at?->isPast()) return 'expired';
        if (! $state->expires_at && !in_array($state->mode, ['menu', 'chat'], true) && $state->updated_at?->lt(now()->subMinutes(30))) return 'expired';
        if ($state->mode === 'chat' && (! $active || (int)$state->conversation_id !== (int)$active->id)) return 'invalid_conversation_state';
        if (in_array($state->mode, ['direct', 'direct_compose', 'direct_review'], true)) {
            $id=(int)($state->direct_recipient_id ?: ($state->direct_context['recipient_id'] ?? 0));
            if ($id < 1 || $id === $user->id || !User::whereKey($id)->where('status','active')->exists()) return 'invalid_direct_state';
        }
        if (str_starts_with($state->mode ?? '', 'game_')) {
            $id=(int)($state->game_context['session_id'] ?? 0); $s=$id ? GameSession::find($id) : null;
            if (!$s || !$s->participants()->whereKey($user->id)->exists() || in_array($s->status,[GameStatus::Expired,GameStatus::Cancelled,GameStatus::Completed],true) || ($s->expires_at?->isPast() && $s->status !== GameStatus::Completed)) return 'invalid_game_state';
        }
        if (in_array($state->mode, ['search_male','search_female'], true) && !DB::table('matchmaking_searches')->where('user_id',$user->id)->where('status','waiting')->exists()) return 'missing_matchmaking_state';
        return null;
    }
}