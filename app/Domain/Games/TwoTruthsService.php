<?php

namespace App\Domain\Games;

use App\Domain\Moderation\ContactInformationGuard;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;

class TwoTruthsService
{
    public function __construct(
        private readonly GameService $games,
        private readonly ContactInformationGuard $guard,
        private readonly BlockService $blocks,
        private readonly SocialNotificationService $notifications,
    ) {}

    public function start(GameSession $session): void
    {
        $this->run($session, null, function (GameSession $s): array {
            if (isset($s->state['tt_phase'])) {
                return [];
            }
            $this->requireActive($s);
            $participants = $s->participants()->pluck('users.id')->values()->all();
            if (count($participants) !== 2) {
                throw new \DomainException('Two players are required.');
            }
            $creator = (int) $s->created_by;
            $guesser = (int) ($participants[0] === $creator ? $participants[1] : $participants[0]);
            $state = $s->state ?? [];
            $state['tt_phase'] = 'statements';
            $state['tt_creator_id'] = $creator;
            $state['tt_guesser_id'] = $guesser;
            $state['tt_statements'] = [];
            $state['tt_correct_guesses'] = [$creator => 0, $guesser => 0];
            $state['tt_started_at'] = now()->toIso8601String();
            $s->update(['state' => $state, 'current_round' => 1]);
            $this->notify($s, $creator, 'start', __('Your turn: enter three distinct statements, one at a time.'));

            return [];
        });
    }

    public function addStatement(User $user, GameSession $session, string $statement): array
    {
        return $this->run($session, $user, function (GameSession $s) use ($user, $statement): array {
            $this->requireActive($s);
            $state = $s->state ?? [];
            if (($state['tt_phase'] ?? null) !== 'statements' || (int) ($state['tt_creator_id'] ?? 0) !== $user->id) {
                throw new \DomainException('It is not your statement turn.');
            }
            $text = preg_replace('/\s+/u', ' ', trim($statement));
            if ($text === '') {
                throw new \DomainException('Statement cannot be empty.');
            }
            if (mb_strlen($text) > (int) config('two_truths.statement_max_length', 240)) {
                throw new \DomainException('Statement is too long.');
            }
            if ($this->guard->blocked($text) || preg_match('/(?<!\d)\d{5,}(?!\d)/u', $text) || $s->participants()->get()->contains(fn ($p) => $p->telegram_username && str_contains(mb_strtolower($text), mb_strtolower($p->telegram_username)))) {
                throw new \DomainException('Telegram IDs and contact links cannot be exchanged here.');
            }
            $statements = $state['tt_statements'] ?? [];
            if (in_array(mb_strtolower($text), array_map('mb_strtolower', $statements), true)) {
                throw new \DomainException('Statements must be distinct.');
            }
            if (count($statements) >= 3) {
                throw new \DomainException('All statements are already entered.');
            }
            $statements[] = $text;
            $state['tt_statements'] = $statements;
            $s->update(['state' => $state]);

            return ['count' => count($statements), 'complete' => count($statements) === 3];
        });
    }

    public function selectLie(User $user, GameSession $session, int $lieIndex, ?int $roundNumber = null): array
    {
        $roundNumber ??= $session->current_round;

        return $this->run($session, $user, function (GameSession $s) use ($user, $lieIndex, $roundNumber): array {
            $previous = DB::table('game_rounds')->where(['game_session_id' => $s->id, 'round_number' => $roundNumber])->first();
            if ($previous) {
                $prompt = json_decode($previous->prompt, true);
                if ((int) $prompt['creator_id'] !== $user->id) {
                    throw new \DomainException('It is not your statement turn.');
                }

                return ['duplicate' => true];
            }
            $this->requireActive($s);
            if ($roundNumber !== $s->current_round) {
                throw new \DomainException('That round is no longer available.');
            }
            $state = $s->state ?? [];
            if (($state['tt_phase'] ?? null) !== 'statements' || (int) ($state['tt_creator_id'] ?? 0) !== $user->id) {
                throw new \DomainException('It is not time to select the lie.');
            }
            $statements = array_values($state['tt_statements'] ?? []);
            if (count($statements) !== 3 || $lieIndex < 0 || $lieIndex > 2) {
                throw new \DomainException('Choose exactly one statement as the lie.');
            }
            $order = [0, 1, 2];
            shuffle($order);
            $state['tt_phase'] = 'guessing';
            $state['tt_lie_index'] = $lieIndex;
            $state['tt_presentation_order'] = $order;
            $state['tt_statements'] = $statements;
            $prompt = ['creator_id' => (int) $state['tt_creator_id'], 'guesser_id' => (int) $state['tt_guesser_id'], 'statements' => $statements, 'presentation_order' => $order, 'lie_index' => $lieIndex];
            DB::table('game_rounds')->updateOrInsert(['game_session_id' => $s->id, 'round_number' => $s->current_round], ['prompt' => json_encode($prompt, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $s->update(['state' => $state]);
            $this->clearContext($s);
            $this->notify($s, (int) $state['tt_guesser_id'], 'guess', __('Three statements are ready. Choose the lie.'));

            return ['duplicate' => false];
        });
    }

    public function guessQuestion(GameSession $session): array
    {
        return $this->run($session, null, function (GameSession $s): array {
            $this->requireActive($s);
            if (($s->state['tt_phase'] ?? null) !== 'guessing') {
                throw new \DomainException('The guessing round is not ready.');
            }

            return ['round' => $s->current_round] + $this->guessScreen($s);
        });
    }

    public function guess(User $user, GameSession $session, int $presentedIndex, ?int $roundNumber = null): array
    {
        $roundNumber ??= $session->current_round;

        return $this->run($session, $user, function (GameSession $s) use ($user, $presentedIndex, $roundNumber): array {
            $previous = DB::table('game_rounds')->where(['game_session_id' => $s->id, 'round_number' => $roundNumber])->first();
            if ($previous && DB::table('game_answers')->where(['game_round_id' => $previous->id, 'user_id' => $user->id])->exists()) {
                return ['duplicate' => true, 'completed' => $s->status === GameStatus::Completed];
            }
            $this->requireActive($s);
            if ($roundNumber !== $s->current_round) {
                throw new \DomainException('That round is no longer available.');
            }
            $state = $s->state ?? [];
            if (($state['tt_phase'] ?? null) !== 'guessing' || (int) ($state['tt_guesser_id'] ?? 0) !== $user->id) {
                throw new \DomainException('It is not your guessing turn.');
            }
            if ($presentedIndex < 0 || $presentedIndex > 2) {
                throw new \DomainException('That statement is invalid.');
            }
            $round = DB::table('game_rounds')->where(['game_session_id' => $s->id, 'round_number' => $s->current_round])->lockForUpdate()->firstOrFail();
            $prompt = json_decode($round->prompt, true) ?: [];
            if (DB::table('game_answers')->where(['game_round_id' => $round->id, 'user_id' => $user->id])->exists()) {
                return ['duplicate' => true, 'completed' => false];
            }
            $originalIndex = (int) $prompt['presentation_order'][$presentedIndex];
            $correct = $originalIndex === (int) $prompt['lie_index'];
            DB::table('game_answers')->insert(['game_round_id' => $round->id, 'user_id' => $user->id, 'answer' => (string) $presentedIndex, 'is_correct' => $correct, 'created_at' => now(), 'updated_at' => now()]);
            $correctGuesses = $state['tt_correct_guesses'] ?? [];
            $correctGuesses[(string) $user->id] = (int) ($correctGuesses[(string) $user->id] ?? 0) + (int) $correct;
            $state['tt_correct_guesses'] = $correctGuesses;
            DB::table('game_rounds')->where('id', $round->id)->update(['winner' => $correct ? 'guesser' : 'creator', 'completed_at' => now(), 'updated_at' => now()]);
            $creator = (int) $state['tt_creator_id'];
            $guesser = (int) $state['tt_guesser_id'];
            $lie = $prompt['statements'][(int) $prompt['lie_index']];
            $state['tt_reveals'][(string) $s->current_round] = ['lie' => $lie, 'correct' => $correct];
            $this->notify($s, $creator, 'reveal', ($correct ? __('Correct guess.') : __('Incorrect guess.')).__(' The lie was: ').$lie);
            $s->participants()->updateExistingPivot($user->id, ['score' => $correctGuesses[(string) $user->id]]);
            if ($s->current_round === 1) {
                $state['tt_phase'] = 'statements';
                $state['tt_creator_id'] = $guesser;
                $state['tt_guesser_id'] = $creator;
                $state['tt_statements'] = [];
                $state['tt_lie_index'] = null;
                $state['tt_presentation_order'] = null;
                $s->update(['current_round' => 2, 'state' => $state]);
                $this->notify($s, $guesser, 'statements', __('Your turn: enter three distinct statements, one at a time.'));

                return ['duplicate' => false, 'completed' => false, 'correct' => $correct, 'reverse' => true, 'lie' => $lie];
            }
            $state['tt_phase'] = 'completed';
            $state['tt_result'] = ['correct_guesses' => $correctGuesses, 'reveals' => $state['tt_reveals']];
            $s->update(['status' => GameStatus::Completed, 'state' => $state]);
            $xp = [];
            foreach ($s->participants()->pluck('users.id') as $id) {
                $xp[(int) $id] = (int) config('two_truths.completion_xp', 15) + ((int) ($correctGuesses[(string) $id] ?? 0) * (int) config('two_truths.correct_guess_xp', 5));
            }
            $this->games->settleSocial($s, $xp);
            foreach ($xp as $id => $earned) {
                $s->participants()->updateExistingPivot($id, ['xp_earned' => $earned]);
                $this->notify($s, $id, 'complete', __('Two Truths and a Lie complete. Correct guesses: ').($correctGuesses[$id] ?? 0).__('/1. XP gained: +').$earned);
            }
            $this->clearContext($s);

            return ['duplicate' => false, 'completed' => true, 'correct' => $correct, 'correct_guesses' => $correctGuesses, 'xp' => $xp[$user->id] ?? 0, 'lie' => $lie];
        });
    }

    // Cancellation must commit before reporting an unavailable action.
    private function run(GameSession $session, ?User $user, callable $action): array
    {
        $result = DB::transaction(function () use ($session, $user, $action): array {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->game_type !== GameType::TwoTruthsOneLie || ($user && ! $s->participants()->whereKey($user->id)->exists())) {
                throw new \DomainException('This game is unavailable.');
            }
            $players = $s->participants()->get();
            if (count($players) !== 2) {
                throw new \DomainException('Two players are required.');
            }
            $blocked = $this->blocks->isBlocked($players[0], $players[1]);
            if ($blocked || (in_array($s->status, [GameStatus::Waiting, GameStatus::Active], true) && $s->expires_at?->isPast())) {
                if ($s->status !== GameStatus::Completed) {
                    $s->update(['status' => $blocked ? GameStatus::Cancelled : GameStatus::Expired]);
                }
                $this->clearContext($s);

                return ['unavailable' => true];
            }
            if (in_array($s->status, [GameStatus::Cancelled, GameStatus::Expired], true)) {
                $this->clearContext($s);

                return ['unavailable' => true];
            }

            return $action($s);
        });
        if ($result['unavailable'] ?? false) {
            throw new \DomainException('This game is no longer available.');
        }

        return $result;
    }

    private function requireActive(GameSession $s): void
    {
        if ($s->status !== GameStatus::Active) {
            throw new \DomainException('Two Truths and a Lie is not active.');
        }
    }

    public function view(User $user, GameSession $session): array
    {
        return $this->run($session, $user, function (GameSession $s) use ($user): array {
            $state = $s->state;
            if ($s->status === GameStatus::Completed) {
                return ['phase' => 'completed', 'result' => $state['tt_result'], 'xp' => $s->participants()->whereKey($user->id)->first()->pivot->xp_earned];
            }
            $this->requireActive($s);
            $phase = $state['tt_phase'] ?? 'waiting';
            if ($phase === 'statements' && (int) $state['tt_creator_id'] === $user->id) {
                InteractionState::updateOrCreate(['user_id' => $user->id], ['mode' => 'game_two_truths', 'game_context' => ['session_id' => $s->id, 'round' => $s->current_round]]);

                return ['phase' => 'statements', 'round' => $s->current_round, 'statements' => $state['tt_statements']];
            }
            if ($phase === 'guessing' && (int) $state['tt_guesser_id'] === $user->id) {
                return ['phase' => 'guessing'] + $this->guessQuestion($s);
            }

            return ['phase' => 'waiting'];
        });
    }

    private function clearContext(GameSession $s): void
    {
        InteractionState::where('mode', 'game_two_truths')->where('game_context->session_id', $s->id)->update(['mode' => 'menu', 'game_context' => null]);
    }

    private function notify(GameSession $s, int $recipientId, string $event, string $text): void
    {
        $this->notifications->queue(User::findOrFail($recipientId), 'two_truths', $s->id, $text,
            'two_truths:'.$s->id.':'.$s->current_round.':'.$event.':'.$recipientId,
            [[['text' => __('Open game'), 'callback_data' => 'd:0:game_tt_open_'.$s->id]]]);
    }

    private function guessScreen(GameSession $session): array
    {
        $round = DB::table('game_rounds')->where(['game_session_id' => $session->id, 'round_number' => $session->current_round])->first();
        $data = json_decode($round->prompt, true) ?: [];

        return ['statements' => array_map(fn (int $original) => $data['statements'][$original], $data['presentation_order'])];
    }
}
