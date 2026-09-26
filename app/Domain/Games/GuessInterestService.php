<?php

namespace App\Domain\Games;

use App\Domain\Profiles\ProfileStatus;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Facades\DB;

class GuessInterestService
{
    public function __construct(private readonly GameService $games, private readonly BlockService $blocks, private readonly SocialNotificationService $notifications) {}

    public function start(GameSession $session): void
    {
        $this->run($session, null, function (GameSession $s) {
            if ($s->status !== GameStatus::Active || $s->game_type !== GameType::GuessInterest) {
                throw new \DomainException('Guess Interest is not active.');
            } $p = $s->participants()->get();
            if ($p->count() !== 2 || $p->contains(fn ($u) => $u->profile?->interests()->count() < 1 || DB::table('interests')->whereNotIn('id', $u->profile->interests()->pluck('interests.id'))->count() < 3) || DB::table('interests')->count() < 4) {
                throw new \DomainException("This user doesn't have enough interests for this game.");
            } if (isset($s->state['gi_phase'])) {
                return [];
            } $st = $s->state ?? [];
            $st['gi_phase'] = 'guessing';
            $st['gi_question_index'] = 0;
            $st['gi_total_questions'] = max(1, (int) config('guess_interest.questions_per_player', 5)) * 2;
            $st['gi_turn_user_id'] = (int) $s->created_by;
            $st['gi_correct_guesses'] = [$p[0]->id => 0, $p[1]->id => 0];
            $st['gi_used'] = [$p[0]->id => [], $p[1]->id => []];
            $s->update(['state' => $st, 'current_round' => 1]);
            $this->round($s->fresh());
            $this->notifyTurn($s->fresh());

            return [];
        });
    }

    public function question(GameSession $session): array
    {
        $s = $this->run($session, null, fn ($s) => [$s])[0];
        if ($s->status !== GameStatus::Active || $s->game_type !== GameType::GuessInterest) {
            throw new \DomainException('Guess Interest is not active.');
        } $r = DB::table('game_rounds')->where(['game_session_id' => $s->id, 'round_number' => $s->current_round])->firstOrFail();
        $p = json_decode($r->prompt, true);

        return ['round' => $s->current_round, 'turn_user_id' => (int) $s->state['gi_turn_user_id'], 'options' => $p['options']];
    }

    public function answer(User $user, GameSession $session, string|int $option, ?int $round = null): array
    {
        $round ??= $session->current_round;

        return $this->run($session, $user, function (GameSession $s) use ($user, $option, $round) {
            if ($s->status === GameStatus::Completed) {
                return ['duplicate' => true, 'completed' => true];
            }$this->guard($s, $user);
            if ($round !== $s->current_round) {
                if ($round < $s->current_round && DB::table('game_answers')->join('game_rounds', 'game_rounds.id', '=', 'game_answers.game_round_id')->where('game_session_id', $s->id)->where('round_number', $round)->where('user_id', $user->id)->exists()) {
                    return ['duplicate' => true, 'completed' => false];
                }
                throw new \DomainException('That round is unavailable.');
            }if ((int) $s->state['gi_turn_user_id'] !== $user->id) {
                throw new \DomainException('It is not your turn.');
            }$r = DB::table('game_rounds')->where(['game_session_id' => $s->id, 'round_number' => $s->current_round])->lockForUpdate()->firstOrFail();
            $p = json_decode($r->prompt, true);
            if (is_int($option)) {
                $option = $p['options'][$option] ?? '';
            }
            if (! in_array($option, $p['options'], true)) {
                throw new \DomainException('That interest option is invalid.');
            }$correct = hash_equals((string) $p['answer'], (string) $option);
            if (! DB::table('game_answers')->insertOrIgnore(['game_round_id' => $r->id, 'user_id' => $user->id, 'answer' => $option, 'is_correct' => $correct, 'created_at' => now(), 'updated_at' => now()])) {
                return ['duplicate' => true, 'completed' => false];
            }$st = $s->state;
            $st['gi_correct_guesses'][$user->id] = (int) ($st['gi_correct_guesses'][$user->id] ?? 0) + (int) $correct;
            $st['gi_question_index']++;
            $this->notify($s, User::findOrFail($this->other($s, $user->id)), $correct ? __('Your opponent guessed correctly.') : __('Your opponent guessed incorrectly.'));
            DB::table('game_rounds')->where('id', $r->id)->update(['winner' => $correct ? 'guesser' : 'creator', 'completed_at' => now(), 'updated_at' => now()]);
            if ($st['gi_question_index'] >= ($st['gi_total_questions'] ?? config('guess_interest.questions_per_player', 5) * 2)) {
                $st['gi_phase'] = 'completed';
                $st['gi_result'] = ['correct_guesses' => $st['gi_correct_guesses']];
                $s->update(['status' => GameStatus::Completed, 'state' => $st]);
                $xp = [];
                foreach ($s->participants()->pluck('users.id') as $id) {
                    $xp[$id] = config('guess_interest.completion_xp', 15) + ($st['gi_correct_guesses'][$id] ?? 0) * config('guess_interest.correct_guess_xp', 2);
                }$this->games->settleSocial($s, $xp);
                foreach ($xp as $id => $v) {
                    $s->participants()->updateExistingPivot($id, ['score' => $st['gi_correct_guesses'][$id] ?? 0, 'xp_earned' => $v]);
                    $this->notify($s, User::findOrFail($id), __('Guess Interest complete. Your correct guesses: ').($st['gi_correct_guesses'][$id] ?? 0).__("\nXP gained: +:v1", ['v1' => $v]));
                }$this->clear($s);

                return ['duplicate' => false, 'completed' => true, 'correct' => $correct, 'correct_guesses' => $st['gi_correct_guesses'], 'xp' => $xp[$user->id]];
            } $st['gi_turn_user_id'] = $this->other($s, $user->id);
            $s->update(['current_round' => $s->current_round + 1, 'state' => $st]);
            $this->round($s->fresh());
            $this->notifyTurn($s->fresh());

            return ['duplicate' => false, 'completed' => false, 'correct' => $correct];
        });
    }

    public function view(User $user, GameSession $session): array
    {
        $s = $this->run($session, $user, fn ($s) => [$s])[0];
        if (! $s->participants()->whereKey($user->id)->exists()) {
            throw new \DomainException('You are not part of this game.');
        }if ($s->status === GameStatus::Completed) {
            $r = $s->state['gi_result']['correct_guesses'];
            $o = $this->other($s, $user->id);

            return ['phase' => 'completed', 'correct_guesses' => $r[$user->id] ?? 0, 'opponent_correct_guesses' => $r[$o] ?? 0, 'xp' => $s->participants()->whereKey($user->id)->first()->pivot->xp_earned];
        }$q = $this->question($s);

        return ['phase' => 'guessing', 'your_turn' => (int) $q['turn_user_id'] === $user->id] + $q;
    }

    private function round(GameSession $s): void
    {
        $turn = (int) $s->state['gi_turn_user_id'];
        $target = $this->other($s, $turn);
        $ints = User::findOrFail($target)->profile->interests()->orderBy('id')->get(['interests.id', 'interests.name']);
        $used = $s->state['gi_used'][$turn] ?? [];
        $ans = $ints->first(fn ($i) => ! in_array($i->id, $used, true)) ?? $ints->first();
        $d = DB::table('interests')->where('id', '!=', $ans->id)->whereNotIn('id', $ints->pluck('id'))->inRandomOrder()->limit(3)->get(['name']);
        if ($d->count() < 3) {
            throw new \DomainException('Not enough unrelated interests for this game.');
        }$opts = $d->pluck('name')->push($ans->name)->shuffle()->values()->all();
        $st = $s->state;
        $st['gi_used'][$turn][] = $ans->id;
        $s->update(['state' => $st]);
        DB::table('game_rounds')->insert(['game_session_id' => $s->id, 'round_number' => $s->current_round, 'prompt' => json_encode(['options' => $opts, 'answer' => $ans->name]), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function run(GameSession $session, ?User $user, callable $action): array
    {
        $result = DB::transaction(function () use ($session, $user, $action) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->game_type !== GameType::GuessInterest || ($user && ! $s->participants()->whereKey($user->id)->exists())) {
                throw new \DomainException('This game is unavailable.');
            }
            $players = $s->participants()->get();
            $invalid = $players->count() !== 2 || $players->contains(fn ($p) => $p->status !== UserStatus::Active || $p->profile?->status !== ProfileStatus::Active);
            if (! $invalid && $s->status === GameStatus::Active && isset($s->state['gi_phase'])) {
                $invalid = $players->contains(fn ($p) => $p->profile->interests()->count() < 1 || DB::table('interests')->whereNotIn('id', $p->profile->interests()->pluck('interests.id'))->count() < 3);
            }
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

    private function notifyTurn(GameSession $s): void
    {
        $this->notify($s, User::findOrFail($s->state['gi_turn_user_id']), __('Guess Interest: choose the interest that belongs to your opponent.'));
    }

    private function notify(GameSession $s, User $u, string $text): void
    {
        $this->notifications->queue($u, 'guess_interest', $s->id, $text, 'guess_interest:'.$s->id.':'.($s->current_round ?? 0).':'.$u->id.':'.substr(sha1($text), 0, 8), [[['text' => __('Open game'), 'callback_data' => 'd:0:game_gi_open_'.$s->id]]]);
    }

    private function clear(GameSession $s): void
    {
        InteractionState::where('mode', 'game_guess_interest')->where('game_context->session_id', $s->id)->update(['mode' => 'menu', 'game_context' => null]);
    }
}
