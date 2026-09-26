<?php

namespace App\Domain\Games;

use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;

class ThisOrThatService
{
    public function __construct(private readonly GameService $games) {}

    public function start(GameSession $session): void
    {
        DB::transaction(function () use ($session) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== GameStatus::Active || $s->game_type !== GameType::ThisOrThat) {
                throw new \DomainException('This or That is not active.');
            }
            $count = (int) config('social.this_or_that_questions', 10);
            $ids = DB::table('quiz_questions')->where('is_active', true)->where('category', 'this_or_that')->inRandomOrder()->pluck('id')->take($count)->values()->all();
            if (count($ids) < $count) {
                throw new \DomainException('There are not enough This or That questions available.');
            }
            if (isset($s->state['question_ids'])) {
                return;
            }
            $state = $s->state ?? [];
            $state['question_ids'] = $ids;
            $state['question_index'] = 0;
            $state['question_opened_at'] = now()->toIso8601String();
            $s->update(['state' => $state, 'current_round' => 1]);
            $this->createRound($s, $ids[0]);
        });
    }

    public function question(GameSession $session): array
    {
        $round = DB::table('game_rounds')->where(['game_session_id' => $session->id, 'round_number' => $session->current_round])->firstOrFail();
        $prompt = json_decode($round->prompt, true);

        return ['round' => $session->current_round, 'total' => count($session->state['question_ids'] ?? []), 'question' => $prompt['question'], 'options' => $prompt['options']];
    }

    public function answer(User $user, GameSession $session, string $option, ?int $roundNumber = null): array
    {
        $this->games->assertPlayable($user, $session);

        return DB::transaction(function () use ($user, $session, $option, $roundNumber) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== GameStatus::Active || $s->game_type !== GameType::ThisOrThat) {
                throw new \DomainException('This or That is not active.');
            }
            if ($roundNumber !== null && $roundNumber !== $s->current_round) {
                return ['duplicate' => true, 'resolved' => false, 'completed' => false];
            }
            if (! $s->participants()->whereKey($user->id)->exists()) {
                throw new \DomainException('You are not part of this game.');
            }
            $round = DB::table('game_rounds')->where(['game_session_id' => $s->id, 'round_number' => $s->current_round])->lockForUpdate()->firstOrFail();
            $prompt = json_decode($round->prompt, true) ?: [];
            if (! in_array($option, $prompt['options'], true)) {
                throw new \DomainException('That option is invalid.');
            }
            $inserted = DB::table('game_answers')->insertOrIgnore(['game_round_id' => $round->id, 'user_id' => $user->id, 'answer' => $option, 'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) {
                return ['duplicate' => true, 'resolved' => false, 'completed' => false];
            }
            $answers = DB::table('game_answers')->where('game_round_id', $round->id)->get();
            if ($answers->count() < 2) {
                return ['duplicate' => false, 'resolved' => false, 'completed' => false];
            }
            $state = $s->state ?? [];
            $state['same_answers'] = ($state['same_answers'] ?? 0) + ((string) $answers[0]->answer === (string) $answers[1]->answer ? 1 : 0);
            DB::table('game_rounds')->where('id', $round->id)->update(['winner' => 'none', 'completed_at' => now(), 'updated_at' => now()]);
            $next = (int) $state['question_index'] + 1;
            $total = count($state['question_ids']);
            if ($next >= $total) {
                $state['question_index'] = $next;
                $state['compatibility'] = (int) round(($state['same_answers'] / max(1, $total)) * 100);
                $s->update(['status' => GameStatus::Completed, 'state' => $state]);
                $this->games->settleSocial($s, 15);

                return ['duplicate' => false, 'resolved' => true, 'completed' => true, 'same_answers' => $state['same_answers'], 'total' => $total, 'compatibility' => $state['compatibility'], 'xp' => 15];
            }
            $state['question_index'] = $next;
            $state['question_opened_at'] = now()->toIso8601String();
            $s->update(['current_round' => $s->current_round + 1, 'state' => $state]);
            $this->createRound($s, $state['question_ids'][$next]);

            $this->games->notifyOpen($s->fresh());

            return ['duplicate' => false, 'resolved' => true, 'completed' => false, 'same_answers' => $state['same_answers']];
        });
    }

    private function createRound(GameSession $session, int $id): void
    {
        $q = DB::table('quiz_questions')->find($id);
        DB::table('game_rounds')->insert(['game_session_id' => $session->id, 'round_number' => $session->current_round, 'prompt' => json_encode(['question' => $q->question, 'options' => json_decode($q->options, true)]), 'created_at' => now(), 'updated_at' => now()]);
    }
}
