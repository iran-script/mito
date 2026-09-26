<?php

namespace App\Domain\Chat;

use App\Domain\Moderation\RestrictionService;
use App\Domain\Payments\PaidActionGate;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Facades\DB;

class ChatRequestService
{
    public function __construct(private readonly PaidActionGate $gate, private readonly BlockService $blocks, private readonly ConversationService $conversations) {}

    public function create(User $requester, User $recipient): ChatRequest
    {
        app(RestrictionService::class)->authorize($requester, 'chat_requests_disabled');
        app(RestrictionService::class)->active($recipient);
        if ($requester->is($recipient)) {
            throw new \DomainException('You cannot request yourself.');
        }
        if ($requester->status !== UserStatus::Active || $recipient->status !== UserStatus::Active || $this->blocks->isBlocked($requester, $recipient)) {
            throw new \DomainException('This user is unavailable.');
        }

        return DB::transaction(function () use ($requester, $recipient) {
            $this->conversations->lockUsers($requester->id, $recipient->id);
            if ($this->conversations->activeForEither($requester->id, $recipient->id)) {
                throw new \DomainException('You already have an active chat.');
            }
            $this->expirePair($requester->id, $recipient->id);
            $existing = ChatRequest::where(function ($q) use ($requester, $recipient) {
                $q->where(fn ($pair) => $pair->where('requester_user_id', $requester->id)->where('recipient_user_id', $recipient->id))
                    ->orWhere(fn ($pair) => $pair->where('requester_user_id', $recipient->id)->where('recipient_user_id', $requester->id));
            })->whereIn('status', [ChatRequestStatus::Pending, ChatRequestStatus::Seen])->lockForUpdate()->first();
            if ($existing) {
                throw new \DomainException('An active request already exists.');
            }

            $createdAt = now();

            return ChatRequest::create([
                'requester_user_id' => $requester->id,
                'recipient_user_id' => $recipient->id,
                'status' => ChatRequestStatus::Pending,
                'expires_at' => $createdAt->copy()->addSeconds($this->ttlSeconds()),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        });
    }

    public function markSeen(User $recipient, ChatRequest $request): ChatRequest
    {
        return $this->view($recipient, $request)[0];
    }

    /** @return array{ChatRequest, bool} */
    public function view(User $recipient, ChatRequest $request): array
    {
        if ($request->recipient_user_id !== $recipient->id) {
            throw new \DomainException('Not your request.');
        }

        $result = DB::transaction(function () use ($request) {
            $r = ChatRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($this->isExpired($r)) {
                $this->markExpired($r);

                return null;
            }
            if (! in_array($r->status, [ChatRequestStatus::Pending, ChatRequestStatus::Seen], true)) {
                throw new \DomainException('This request is no longer available.');
            }
            $firstView = $r->status === ChatRequestStatus::Pending;
            if ($firstView) {
                $r->update(['status' => ChatRequestStatus::Seen, 'seen_at' => now()]);
            }

            return [$r->fresh(), $firstView];
        });

        if ($result === null) {
            throw new \DomainException('This request has expired.');
        }

        return $result;
    }

    public function accept(User $recipient, ChatRequest $request): Conversation
    {
        $result = DB::transaction(function () use ($recipient, $request) {
            $this->conversations->lockUsers($request->requester_user_id, $request->recipient_user_id);
            $r = ChatRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->recipient_user_id !== $recipient->id) {
                throw new \DomainException('Only the recipient can accept.');
            }
            $requester = User::findOrFail($r->requester_user_id);
            app(RestrictionService::class)->active($recipient);
            app(RestrictionService::class)->active($requester);
            if ($this->blocks->isBlocked($recipient, $requester)) {
                throw new \DomainException('This user is unavailable.');
            }
            if ($r->status === ChatRequestStatus::Accepted) {
                return $this->conversationFor($r->requester_user_id, $r->recipient_user_id, true);
            }
            if ($this->isExpired($r)) {
                $this->markExpired($r);

                return null;
            }
            if (! in_array($r->status, [ChatRequestStatus::Pending, ChatRequestStatus::Seen], true)) {
                throw new \DomainException('This request is no longer available.');
            }

            if ($this->conversations->activeForEither($r->requester_user_id, $r->recipient_user_id)) {
                $r->update(['status' => ChatRequestStatus::Cancelled, 'cancelled_at' => now()]);

                return false;
            }

            $this->gate->authorize('chat_request_accept', $r->requester_user_id, ['request_id' => $r->id, 'reference_type' => ChatRequest::class, 'reference_id' => $r->id, 'idempotency_key' => 'chat_accept:'.$r->id]);
            $conversation = $this->conversationFor($r->requester_user_id, $r->recipient_user_id, true);
            $conversation->update([
                'origin' => 'manual_request',
                'chat_request_id' => $r->id,
                'accepted_by_user_id' => $recipient->id,
                'activated_at' => now(),
                'engagement_rewarded_at' => null,
            ]);
            $r->update(['status' => ChatRequestStatus::Accepted, 'accepted_at' => now()]);

            return $conversation->fresh();
        });

        if ($result === null) {
            throw new \DomainException('The request deadline has passed.');
        }
        if ($result === false) {
            throw new \DomainException('One of you already has an active chat.');
        }

        return $result;
    }

    public function reject(User $recipient, ChatRequest $request): ChatRequest
    {
        $result = DB::transaction(function () use ($recipient, $request) {
            $r = ChatRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->recipient_user_id !== $recipient->id) {
                throw new \DomainException('Only the recipient can reject.');
            }
            if ($this->isExpired($r)) {
                $this->markExpired($r);

                return null;
            }
            if ($r->status === ChatRequestStatus::Rejected) {
                return $r;
            }
            if (! in_array($r->status, [ChatRequestStatus::Pending, ChatRequestStatus::Seen], true)) {
                throw new \DomainException('This request is no longer available.');
            }
            $r->update(['status' => ChatRequestStatus::Rejected, 'rejected_at' => now()]);

            return $r->fresh();
        });

        if ($result === null) {
            throw new \DomainException('This request is no longer active.');
        }

        return $result;
    }

    public function expire(): int
    {
        $ttl = $this->ttlSeconds();
        $cutoff = now()->subSeconds($ttl);

        return ChatRequest::whereIn('status', [ChatRequestStatus::Pending, ChatRequestStatus::Seen])
            ->where(function ($query) use ($cutoff) {
                $query->where('created_at', '<=', $cutoff)
                    ->orWhere('expires_at', '<=', now());
            })
            ->update([
                'status' => ChatRequestStatus::Expired,
                'expires_at' => DB::raw("created_at + make_interval(secs => {$ttl})"),
                'updated_at' => now(),
            ]);
    }

    private function expirePair(int $a, int $b): void
    {
        $ttl = $this->ttlSeconds();
        ChatRequest::where(function ($query) use ($a, $b) {
            $query->where(fn ($pair) => $pair->where('requester_user_id', $a)->where('recipient_user_id', $b))
                ->orWhere(fn ($pair) => $pair->where('requester_user_id', $b)->where('recipient_user_id', $a));
        })->whereIn('status', [ChatRequestStatus::Pending, ChatRequestStatus::Seen])
            ->where(function ($query) use ($ttl) {
                $query->where('created_at', '<=', now()->subSeconds($ttl))
                    ->orWhere('expires_at', '<=', now());
            })->lockForUpdate()->get()->each(fn (ChatRequest $request) => $this->markExpired($request));
    }

    private function isExpired(ChatRequest $request): bool
    {
        $ttlBoundary = $request->created_at->addSeconds($this->ttlSeconds());

        return now()->greaterThanOrEqualTo($ttlBoundary)
            || ($request->expires_at && now()->greaterThanOrEqualTo($request->expires_at));
    }

    private function markExpired(ChatRequest $request): void
    {
        $request->update([
            'status' => ChatRequestStatus::Expired,
            'expires_at' => $request->created_at->addSeconds($this->ttlSeconds()),
        ]);
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('social.chat_request_ttl_seconds', 120));
    }

    private function conversationFor(int $a, int $b, bool $create = false): ?Conversation
    {
        [$low,$high] = $a < $b ? [$a, $b] : [$b, $a];
        $c = Conversation::where(['user_low_id' => $low, 'user_high_id' => $high])->lockForUpdate()->first();
        if (! $c && $create) {
            $c = Conversation::create(['user_low_id' => $low, 'user_high_id' => $high, 'status' => ConversationStatus::Active]);
        }
        if ($c && $create) {
            $c->update(['status' => ConversationStatus::Active, 'is_protected' => false]);
            $c->participants()->syncWithoutDetaching([$low, $high]);
        }

        return $c?->fresh();
    }
}
