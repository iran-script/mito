<?php

namespace App\Domain\Games;

use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;

class SpeedQuizService
{
    public function __construct(private readonly SpeedQuizScoringService $scoring, private readonly GameService $games) {}

    public function start(GameSession $session): void
    {
        DB::transaction(function () use ($session) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== GameStatus::Active || $s->game_type !== GameType::SpeedQuiz) {
                throw new \DomainException('Speed Quiz is not active.');
            }
            $ids = DB::table('quiz_questions')->where('is_active', true)->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'this_or_that'))->orderBy('id')->pluck('id')->shuffle()->take((int) config('speed_quiz.questions_per_match', 10))->values()->all();
            if (count($ids) < (int) config('speed_quiz.questions_per_match', 10)) {
                throw new \DomainException('There are not enough quiz questions available.');
            }
            if (isset($s->state['question_ids'])) {
                return;
            }
            $state = $s->state ?? [];
            $state['question_ids'] = $ids;
            $state['question_index'] = 0;
            $state['scores'] = [$s->created_by => 0];
            $opponent = $s->participants()->where('users.id', '!=', $s->created_by)->value('users.id');
            $state['scores'][$opponent] = 0;
            $state['question_opened_at'] = now()->toIso8601String();
            $s->update(['state' => $state, 'current_round' => 1]);
            $this->createRound($s, $ids[0]);
        });
    }

    public function question(GameSession $session): array
    {
        $round = DB::table('game_rounds')->where(['game_session_id' => $session->id, 'round_number' => $session->current_round])->firstOrFail();
        $prompt = json_decode($round->prompt, true) ?: [];

        return ['round' => $session->current_round, 'total' => (int) config('speed_quiz.questions_per_match', 10), 'question' => $prompt['question'], 'options' => $prompt['options']];
    }

    public function answer(User $user, GameSession $session, string $option, ?\DateTimeImmutable $receivedAt = null, ?int $roundNumber = null): array
    {
        $this->games->assertPlayable($user, $session);

        return DB::transaction(function () use ($user, $session, $option, $receivedAt, $roundNumber) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== GameStatus::Active || $s->game_type !== GameType::SpeedQuiz) {
                throw new \DomainException('Speed Quiz is not active.');
            }
            if (! $s->participants()->whereKey($user->id)->exists()) {
                throw new \DomainException('You are not part of this game.');
            }
            if ($roundNumber !== null && $roundNumber !== $s->current_round) {
                return ['duplicate' => true, 'resolved' => false, 'completed' => false];
            }
            $round = DB::table('game_rounds')->where(['game_session_id' => $s->id, 'round_number' => $s->current_round])->lockForUpdate()->firstOrFail();
            $prompt = json_decode($round->prompt, true) ?: [];
            if (! in_array($option, $prompt['options'], true)) {
                throw new \DomainException('That answer is invalid.');
            }
            $opened = new \DateTimeImmutable($s->state['question_opened_at']);
            $at = $receivedAt ?? new \DateTimeImmutable('now');
            $latency = max(0, ($at->getTimestamp() - $opened->getTimestamp()) * 1000 + intdiv((int) $at->format('v'), 1) - intdiv((int) $opened->format('v'), 1));
            $correct = hash_equals((string) $prompt['correct_option'], (string) $option);
            $score = $this->scoring->score($correct, $latency);
            $inserted = DB::table('game_answers')->insertOrIgnore(['game_round_id' => $round->id, 'user_id' => $user->id, 'answer' => $option, 'is_correct' => $correct, 'response_ms' => $latency, 'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) {
                return ['duplicate' => true, 'resolved' => false, 'completed' => false, 'score' => 0];
            }
            $answers = DB::table('game_answers')->where('game_round_id', $round->id)->get();
            if ($answers->count() < 2) {
                return ['duplicate' => false, 'resolved' => false, 'completed' => false, 'score' => $score];
            }
            $state = $s->state ?? [];
            foreach ($answers as $answer) {
                $state['scores'][$answer->user_id] = ($state['scores'][$answer->user_id] ?? 0) + $this->scoring->score((bool) $answer->is_correct, (int) $answer->response_ms);
            }
            DB::table('game_rounds')->where('id', $round->id)->update(['winner' => 'resolved', 'completed_at' => now(), 'updated_at' => now()]);
            $last = (int) $state['question_index'] + 1;
            if ($last >= count($state['question_ids'])) {
                $s->update(['status' => GameStatus::Completed, 'state' => array_merge($state, ['question_index' => $last])]);
                $winner = $this->winner($state['scores']);
                $this->games->settleCompleted($s, $winner);

                return ['duplicate' => false, 'resolved' => true, 'completed' => true, 'score' => $score, 'winner' => $winner, 'scores' => $state['scores']];
            }
            $state['question_index'] = $last;
            $state['question_opened_at'] = now()->toIso8601String();
            $s->update(['current_round' => $s->current_round + 1, 'state' => $state]);
            $this->createRound($s, $state['question_ids'][$last]);

            $this->games->notifyOpen($s->fresh());

            return ['duplicate' => false, 'resolved' => true, 'completed' => false, 'score' => $score, 'scores' => $state['scores']];
        });
    }

    public function timeout(GameSession $session): array
    {
        if (! $session->state || now()->lt((new \DateTimeImmutable($session->state['question_opened_at']))->modify('+'.config('speed_quiz.question_timeout_seconds', 45).' seconds'))) {
            return ['timed_out' => false];
        }
        $round = DB::table('game_rounds')->where(['game_session_id' => $session->id, 'round_number' => $session->current_round])->first();
        foreach ($session->participants as $p) {
            DB::table('game_answers')->insertOrIgnore(['game_round_id' => $round->id, 'user_id' => $p->id, 'answer' => '', 'is_correct' => false, 'response_ms' => config('speed_quiz.question_timeout_seconds', 45) * 1000, 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['timed_out' => true];
    }

    private function createRound(GameSession $session, int $questionId): void
    {
        $q = DB::table('quiz_questions')->find($questionId);
        DB::table('game_rounds')->insert(['game_session_id' => $session->id, 'round_number' => $session->current_round, 'prompt' => json_encode(['question' => $q->question, 'options' => json_decode($q->options, true), 'correct_option' => $q->correct_option]), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function winner(array $scores): string
    {
        $values = array_values($scores);
        if (count($values) < 2 || $values[0] === $values[1]) {
            return 'draw';
        } $id = array_keys($scores, max($values), true)[0];

        return 'player_'.$id;
    }
}
