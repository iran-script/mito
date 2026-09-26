<?php

namespace App\Console\Commands;

use App\Domain\Chat\ConversationService;
use App\Domain\Games\GameSession;
use App\Domain\Telegram\InteractionState;
use App\Domain\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UserState extends Command
{
    protected $signature = 'mito:user-state {user}';

    protected $description = 'Inspect transient Telegram state without changing durable data';

    public function handle(ConversationService $conversations): int
    {
        $v = (string) $this->argument('user');
        $u = ctype_digit($v) ? User::find((int) $v) : User::where('public_mito_id', ltrim($v, '/'))->first();
        if (! $u) {
            $this->error('User not found.');

            return self::FAILURE;
        } $s = InteractionState::firstOrCreate(['user_id' => $u->id]);
        $this->table(['Field', 'Value'], [['user_id', $u->id], ['public_mito_id', $u->public_mito_id], ['interaction_state', $s->mode], ['expires_at', (string) $s->expires_at], ['last_activity_at', (string) $u->last_activity_at], ['active_conversation', $conversations->activeFor($u)?->id ?? 'no'], ['anonymous_matchmaking', DB::table('matchmaking_searches')->where('user_id', $u->id)->where('status', 'waiting')->exists() ? 'waiting' : 'no'], ['game_matchmaking', DB::table('game_matchmaking_queue')->where('user_id', $u->id)->where('status', 'waiting')->exists() ? 'waiting' : 'no'], ['direct_draft', isset($s->direct_context['draft']) ? 'yes' : 'no'], ['game_session', $this->gameSession($s)]]);

        return self::SUCCESS;
    }

    private function gameSession(InteractionState $s): string|int
    {
        $id = (int) ($s->game_context['session_id'] ?? 0);

        return $id && GameSession::find($id) ? $id : 'no';
    }
}
