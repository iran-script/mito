<?php

namespace App\Domain\Games;

use App\Domain\Profiles\ProfileStatus;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use App\Support\Presentation;
use Illuminate\Support\Facades\DB;

class GuessNumberService
{
    public function __construct(private readonly GameService $games, private readonly BlockService $blocks, private readonly SocialNotificationService $notifications) {}

    public function start(GameSession $session): void
    {
        $this->run($session, null, function (GameSession $s) {
            if ($s->status !== GameStatus::Active || $s->game_type !== GameType::GuessNumber) {
                throw new \DomainException('Guess Number is not active.');
            }if (isset($s->state['gn_phase'])) {
                return [];
            }$st = $s->state ?? [];
            $st['gn_phase'] = 'guessing';
            $st['gn_min'] = config('guess_number.min', 1);
            $st['gn_max'] = config('guess_number.max', 100);
            if ($st['gn_min'] < 1 || $st['gn_max'] < $st['gn_min'] || $st['gn_max'] > 999999999) {
                throw new \DomainException('The number range is unavailable.');
            }
            $st['gn_secret'] = random_int($st['gn_min'], $st['gn_max']);
            $st['gn_turn_user_id'] = (int) $s->created_by;
            $st['gn_guesses'] = [];
            $s->update(['state' => $st]);
            $this->notify($s, User::findOrFail($s->created_by), __('Guess Number started. Enter an integer from :v1 to :v2.', ['v1' => $st['gn_min'], 'v2' => $st['gn_max']]));

            return [];
        });
    }

    public function guess(User $user, GameSession $session, string $raw, ?string $inputId = null, ?int $turn = null): array
    {
        return $this->run($session, $user, function (GameSession $s) use ($user, $raw, $inputId, $turn) {
            $candidate = trim($raw);
            if ($inputId !== null && collect($s->state['gn_guesses'] ?? [])->contains(fn ($g) => ($g['input_id'] ?? null) === $inputId && (int) $g['user_id'] === $user->id)) {
                return ['duplicate' => true, 'completed' => $s->status === GameStatus::Completed];
            }
            $this->guard($s, $user);
            if ($turn !== null && $turn !== $s->current_round) {
                throw new \DomainException('Open the current turn before guessing.');
            }
            if ((int) $s->state['gn_turn_user_id'] !== $user->id) {
                throw new \DomainException('It is not your turn.');
            }
            if (! preg_match('/^[0-9]{1,9}$/D', $candidate)) {
                throw new \DomainException('Enter a whole number.');
            }
            $g = (int) $candidate;
            $min = (int) $s->state['gn_min'];
            $max = (int) $s->state['gn_max'];
            if ($g < $min || $g > $max) {
                throw new \DomainException("Choose a number from {$min} to {$max}.");
            }$st = $s->state;
            $st['gn_guesses'][] = ['user_id' => $user->id, 'guess' => $g, 'input_id' => $inputId];
            $secret = (int) $st['gn_secret'];
            if ($g === $secret) {
                $st['gn_phase'] = 'completed';
                $st['gn_result'] = ['winner_id' => $user->id, 'loser_id' => $this->other($s, $user->id), 'secret' => $secret];
                $s->update(['status' => GameStatus::Completed, 'state' => $st]);
                $this->games->settleCompleted($s, 'player_'.$user->id);
                foreach ($s->participants()->pluck('users.id') as $id) {
                    $this->notify($s, User::findOrFail($id), $id === $user->id ? __('Correct! The number was :v1. You win.', ['v1' => $secret]) : __('The number was :v1. Your opponent wins.', ['v1' => $secret]));
                }$this->clear($s);

                return ['duplicate' => false, 'completed' => true, 'correct' => true, 'winner_id' => $user->id, 'secret' => $secret];
            }$st['gn_turn_user_id'] = $this->other($s, $user->id);
            $s->update(['state' => $st, 'current_round' => $s->current_round + 1]);
            InteractionState::where('mode', 'game_guess_number')->where('game_context->session_id', $s->id)->update(['game_context' => json_encode(['session_id' => $s->id, 'turn' => $s->current_round])]);
            $hint = $g < $secret ? 'Higher' : 'Lower';
            $this->notify($s, User::findOrFail($st['gn_turn_user_id']), __('Opponent guessed :v1: :v2. Your turn. Enter a whole number from :v3 to :v4.', ['v1' => $g, 'v2' => Presentation::label($hint), 'v3' => $min, 'v4' => $max]));

            return ['duplicate' => false, 'completed' => false, 'correct' => false, 'hint' => $hint, 'next_user_id' => $st['gn_turn_user_id']];
        });
    }

    public function view(User $user, GameSession $session): array
    {
        $s = $this->run($session, $user, fn ($s) => [$s])[0];
        if (! $s->participants()->whereKey($user->id)->exists()) {
            throw new \DomainException('You are not part of this game.');
        }if ($s->status === GameStatus::Completed) {
            return ['phase' => 'completed', 'winner_id' => $s->state['gn_result']['winner_id'], 'secret' => $s->state['gn_result']['secret'], 'you_won' => (int) $s->state['gn_result']['winner_id'] === $user->id];
        }

        $this->guard($s, $user);

        return ['phase' => 'guessing', 'turn' => $s->current_round, 'your_turn' => (int) $s->state['gn_turn_user_id'] === $user->id, 'min' => $s->state['gn_min'], 'max' => $s->state['gn_max']];
    }

    private function run(GameSession $session, ?User $user, callable $action): array
    {
        $result = DB::transaction(function () use ($session, $user, $action) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->game_type !== GameType::GuessNumber || ($user && ! $s->participants()->whereKey($user->id)->exists())) {
                throw new \DomainException('This game is unavailable.');
            }
            $players = $s->participants()->get();
            $invalid = $players->count() !== 2 || $players->contains(fn ($p) => $p->status !== UserStatus::Active || $p->profile?->status !== ProfileStatus::Active);
            $blocked = ! $invalid && $this->blocks->isBlocked($players[0], $players[1]);
            $expired = $s->status !== GameStatus::Completed && $s->expires_at?->isPast();
            if ($invalid || $blocked || $expired || in_array($s->status, [GameStatus::Cancelled, GameStatus::Expired], true)) {
                if ($s->status !== GameStatus::Completed) {
                    $s->update(['status' => $invalid || $blocked || $s->status === GameStatus::Cancelled ? GameStatus::Cancelled : GameStatus::Expired]);
                }
                $this->clear($s);

                return ['unavailable' => true];
            }

            return $action($s);
        });
        if ($result['unavailable'] ?? false) {
            throw new \DomainException('This game is no longer available.');
        }

        return $result;
    }

    private function guard(GameSession $s, User $u): void
    {
        if ($s->status !== GameStatus::Active) {
            throw new \DomainException('This game is not active.');
        }
    }

    private function other(GameSession $s, int $id): int
    {
        return (int) $s->participants()->where('users.id', '!=', $id)->value('users.id');
    }

    private function notify(GameSession $s, User $u, string $text): void
    {
        $this->notifications->queue($u, 'guess_number', $s->id, $text, 'guess_number:'.$s->id.':'.count($s->state['gn_guesses'] ?? []).':'.$u->id.':'.substr(sha1($text), 0, 8), [[['text' => __('Open game'), 'callback_data' => 'd:0:game_gn_open_'.$s->id]]]);
    }

    private function clear(GameSession $s): void
    {
        InteractionState::where('mode', 'game_guess_number')->where('game_context->session_id', $s->id)->update(['mode' => 'menu', 'game_context' => null]);
    }
}
