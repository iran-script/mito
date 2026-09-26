<?php

namespace App\Console\Commands;

use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\Keyboard;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireGameMatchmaking extends Command
{
    protected $signature = 'games:matchmaking-expire';

    protected $description = 'Expire stale random game matchmaking entries';

    public function handle(SocialNotificationService $notifications): int
    {
        $rows = DB::table('game_matchmaking_queue')
            ->where('status', 'waiting')
            ->where('expires_at', '<=', now())
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $updated = DB::table('game_matchmaking_queue')->where('id', $row->id)->where('status', 'waiting')->update(['status' => 'expired', 'updated_at' => now()]);
            if ($updated) {
                $user = User::find($row->user_id);
                if ($user) {
                    $revision = (int) (RegistrationState::where('user_id', $user->id)->value('revision') ?? 0);
                    $notifications->queue($user, 'game_matchmaking_timeout', $row->id, __('No one matched right now. Try again?'), 'game_matchmaking_timeout:'.$row->id, [[Keyboard::button('d', $revision, __('Search again'), 'game_match_retry')], [Keyboard::button('d', $revision, __('Back'), 'games')]]);
                }
            }
        }

        return self::SUCCESS;
    }
}
