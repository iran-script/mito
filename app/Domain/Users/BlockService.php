<?php

namespace App\Domain\Users;

use App\Domain\Chat\ChatRequest;
use App\Domain\Chat\ChatRequestStatus;
use App\Domain\Chat\Conversation;
use App\Domain\Chat\ConversationStatus;
use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Telegram\InteractionState;
use Illuminate\Support\Facades\DB;

class BlockService
{
    public function block(User $blocker, User $blocked): void
    {
        if ($blocker->is($blocked)) {
            throw new \InvalidArgumentException('You cannot block yourself.');
        }
        DB::transaction(function () use ($blocker, $blocked) {
            DB::table('user_blocks')->insertOrIgnore(['blocker_user_id' => $blocker->id, 'blocked_user_id' => $blocked->id, 'created_at' => now()]);
            ChatRequest::where(function ($q) use ($blocker, $blocked) {
                $q->where(function ($pair) use ($blocker, $blocked) {
                    $pair->where('requester_user_id', $blocker->id)->where('recipient_user_id', $blocked->id);
                })->orWhere(function ($pair) use ($blocker, $blocked) {
                    $pair->where('requester_user_id', $blocked->id)->where('recipient_user_id', $blocker->id);
                });
            })->whereIn('status', [ChatRequestStatus::Pending, ChatRequestStatus::Seen])->update(['status' => ChatRequestStatus::Cancelled, 'cancelled_at' => now(), 'updated_at' => now()]);
            GameSession::whereIn('game_type', [GameType::TwoTruthsOneLie, GameType::GuessInterest, GameType::GuessNumber])
                ->whereIn('status', [GameStatus::Waiting, GameStatus::Active])
                ->whereHas('participants', fn ($q) => $q->where('users.id', $blocker->id))
                ->whereHas('participants', fn ($q) => $q->where('users.id', $blocked->id))
                ->update(['status' => GameStatus::Cancelled, 'updated_at' => now()]);
            $conversation = $this->conversation($blocker, $blocked);
            if ($conversation) {
                $conversation->update(['status' => ConversationStatus::Blocked, 'is_protected' => false, 'updated_at' => now()]);
                InteractionState::whereIn('user_id', [$blocker->id, $blocked->id])->update([
                    'mode' => 'menu', 'conversation_id' => null, 'direct_recipient_id' => null, 'updated_at' => now(),
                ]);
            }
        });
    }

    public function unblock(User $blocker, User $blocked): void
    {
        DB::table('user_blocks')->where(['blocker_user_id' => $blocker->id, 'blocked_user_id' => $blocked->id])->delete();
    }

    public function isBlocked(User $a, User $b): bool
    {
        return DB::table('user_blocks')->where(function ($q) use ($a, $b) {
            $q->where('blocker_user_id', $a->id)->where('blocked_user_id', $b->id);
        })->orWhere(function ($q) use ($a, $b) {
            $q->where('blocker_user_id', $b->id)->where('blocked_user_id', $a->id);
        })->exists();
    }

    private function conversation(User $a, User $b): ?Conversation
    {
        [$low,$high] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        return Conversation::where(['user_low_id' => $low, 'user_high_id' => $high])->lockForUpdate()->first();
    }
}
