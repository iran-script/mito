<?php

namespace App\Domain\Telegram\Jobs;

use App\Domain\Discovery\AnonymousMatchmakingService;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Keyboard;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class MatchmakingPoll implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId, public int $generation) {}

    public function handle(AnonymousMatchmakingService $matcher, SocialNotificationService $notifications): void
    {
        $search = DB::table('matchmaking_searches')->where('user_id', $this->userId)->first();
        if (! $search || $search->status !== 'waiting' || (int) $search->generation !== $this->generation) {
            return;
        }
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }
        $revision = RegistrationState::where('user_id', $this->userId)->value('revision') ?? 0;
        if (now()->gte($search->expires_at)) {
            DB::table('matchmaking_searches')->where('user_id', $this->userId)->where('status', 'waiting')->update(['status' => 'timed_out', 'updated_at' => now()]);
            $notifications->queue($user, 'matchmaking_timeout', $this->generation, __('No one matched right now. Try again?'), "match_timeout:{$this->userId}:{$this->generation}", [[Keyboard::button('d', $revision, __('Search again'), 'match_retry')], [Keyboard::button('d', $revision, __('Back'), 'search')]]);

            return;
        }
        $pair = $matcher->attempt($this->userId, $this->generation);
        if ($pair) {
            InteractionState::whereIn('user_id', [$pair['first'], $pair['second']])->update([
                'mode' => 'chat',
                'conversation_id' => $pair['conversation_id'],
                'direct_recipient_id' => null,
                'updated_at' => now(),
            ]);
            foreach ([[$pair['first'], $pair['first_generation']], [$pair['second'], $pair['second_generation']]] as [$uid, $gen]) {
                $recipient = User::findOrFail($uid);
                $notifications->queueReply(
                    $recipient,
                    'matchmaking_match',
                    $pair['conversation_id'],
                    __("Someone's here!\nChat connected.\n\nYou can send a message right here."),
                    Keyboard::chatReply(false),
                    "match_found:{$uid}:{$gen}",
                );
            }

            return;
        }
        self::dispatch($this->userId, $this->generation)->onConnection('database')->onQueue('telegram')->delay(now()->addSeconds(5));
    }
}
