<?php

namespace App\Domain\Games;

use App\Domain\Moderation\RestrictionService;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GameService
{
    public function __construct(private readonly BlockService $blocks, private readonly SocialNotificationService $notifications) {}

    public function invite(User $from, User $to, GameType $type): GameSession
    {
        app(RestrictionService::class)->authorize($from, 'game_invites_disabled');
        app(GameAvailability::class)->assertEnabled($type);
        $from = $from->fresh();
        $to = $to->fresh();
        if ($from->profile?->status !== ProfileStatus::Active || $to->profile?->status !== ProfileStatus::Active || $from->id === $to->id || $from->status !== UserStatus::Active || $to->status !== UserStatus::Active || $this->blocks->isBlocked($from, $to)) {
            throw new \DomainException('This player cannot be invited.');
        }

        return DB::transaction(function () use ($from, $to, $type) {
            $duplicate = GameSession::where('game_type', $type)->whereIn('status', [GameStatus::Waiting, GameStatus::Accepted, GameStatus::Active])->where(function ($q) use ($from, $to) {
                $q->where('created_by', $from->id)->whereHas('participants', fn ($p) => $p->where('users.id', $to->id))->orWhere('created_by', $to->id)->whereHas('participants', fn ($p) => $p->where('users.id', $from->id));
            })->exists();
            if ($duplicate) {
                throw new \DomainException('An active game already exists.');
            }
            $session = GameSession::create(['game_type' => $type, 'status' => GameStatus::Waiting, 'created_by' => $from->id, 'expires_at' => now()->addMinutes((int) config('social.game_invitation_expiry_minutes', 30)), 'state' => ['opponent_id' => $to->id]]);
            $session->participants()->attach([$from->id, $to->id]);
            $this->notifications->queue($to, match ($type) {
                GameType::TwoTruthsOneLie => 'two_truths', GameType::GuessInterest => 'guess_interest', GameType::GuessNumber => 'guess_number', default => 'game_invite'
            }, $session->id, __('You have a new game invitation.'), 'game_invite:'.$session->id.':'.$to->id,
                [[['text' => __('Accept game'), 'callback_data' => 'd:0:game_accept_'.$session->id]]]);

            return $session;
        });
    }

    public function accept(User $user, GameSession $session): void
    {
        DB::transaction(function () use ($user, $session) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->status === GameStatus::Waiting) {
                app(GameAvailability::class)->assertEnabled($s->game_type);
            }
            if ((int) ($s->state['opponent_id'] ?? 0) !== $user->id) {
                throw new \DomainException('Only the invited player can accept.');
            }
            $players = $s->participants()->get();
            if ($players->count() !== 2 || $players->contains(fn ($p) => $p->status !== UserStatus::Active || $p->profile?->status !== ProfileStatus::Active) || $this->blocks->isBlocked($players[0], $players[1])) {
                if ($s->status !== GameStatus::Completed) {
                    $s->update(['status' => GameStatus::Cancelled]);
                }

                return;
            }
            if (in_array($s->status, [GameStatus::Active, GameStatus::Completed], true)) {
                return;
            }
            if ($s->expires_at?->isPast()) {
                $s->update(['status' => GameStatus::Expired]);

                return;
            }
            if (! $s->participants()->whereKey($user->id)->exists() || $s->status !== GameStatus::Waiting || ($s->expires_at && $s->expires_at->isPast())) {
                throw new \DomainException('This game invitation is no longer available.');
            }
            $s->update(['status' => GameStatus::Active, 'state' => array_merge($s->state ?? [], ['started_at' => now()->toIso8601String()])]);
            if ($s->game_type === GameType::TwoTruthsOneLie) {
                app(TwoTruthsService::class)->start($s);
            } elseif ($s->game_type === GameType::GuessInterest) {
                try {
                    app(GuessInterestService::class)->start($s);
                } catch (\DomainException $e) {
                    $s->update(['status' => GameStatus::Cancelled]);
                    foreach ($s->participants()->get() as $player) {
                        $this->notifications->queue($player, 'game_unavailable', $s->id, __('Guess Interest cannot start: both players need usable interests and three unrelated options. Please choose another game.'), 'gi_unavailable:'.$s->id.':'.$player->id);
                    }
                }
            } elseif ($s->game_type === GameType::GuessNumber) {
                app(GuessNumberService::class)->start($s);
            }
            if ($s->game_type === GameType::SpeedQuiz) {
                app(SpeedQuizService::class)->start($s);
            }
            if ($s->game_type === GameType::ThisOrThat) {
                app(ThisOrThatService::class)->start($s);
            }
            if (! in_array($s->game_type, [GameType::SpeedQuiz, GameType::ThisOrThat, GameType::TwoTruthsOneLie, GameType::GuessInterest, GameType::GuessNumber], true)) {
                $this->ensureRound($s);
            }
            if ($s->fresh()->status === GameStatus::Active) {
                $this->notifyOpen($s->fresh());
            }
        });
    }

    public function ensureRound(GameSession $session): int
    {
        if (in_array($session->game_type, [GameType::TwoTruthsOneLie, GameType::GuessInterest, GameType::GuessNumber], true)) {
            throw new \DomainException('Use the dedicated game flow.');
        }
        $prompt = $this->prompt($session->game_type, $session);

        return DB::table('game_rounds')->insertOrIgnore(['game_session_id' => $session->id, 'round_number' => $session->current_round, 'prompt' => json_encode($prompt, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function answer(User $user, GameSession $session, string $answer, ?int $responseMs = null, ?int $roundNumber = null): array
    {
        $this->assertPlayable($user, $session);

        return DB::transaction(function () use ($user, $session, $answer, $roundNumber) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->game_type !== GameType::RockPaperScissors) {
                throw new \DomainException('Use the dedicated game flow.');
            }
            if ($s->status !== GameStatus::Active || ! $s->participants()->whereKey($user->id)->exists()) {
                throw new \DomainException('This game is not active.');
            }
            if ($roundNumber !== null && $roundNumber !== $s->current_round) {
                return ['duplicate' => true, 'completed' => false];
            }
            if (! in_array($answer, ['rock', 'paper', 'scissors'], true)) {
                throw new \DomainException('Invalid move.');
            }
            $round = DB::table('game_rounds')->where(['game_session_id' => $s->id, 'round_number' => $s->current_round])->lockForUpdate()->firstOrFail();
            $prompt = json_decode($round->prompt, true) ?: [];
            $serverMs = isset($prompt['opened_at']) ? max(0, now()->diffInMilliseconds(CarbonImmutable::parse($prompt['opened_at']))) : null;
            $correct = isset($prompt['correct_option']) ? hash_equals((string) $prompt['correct_option'], (string) $answer) : null;
            $inserted = DB::table('game_answers')->insertOrIgnore(['game_round_id' => $round->id, 'user_id' => $user->id, 'answer' => mb_substr($answer, 0, 500), 'is_correct' => $correct, 'response_ms' => $serverMs, 'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) {
                return ['duplicate' => true, 'completed' => false];
            }
            $answers = DB::table('game_answers')->where('game_round_id', $round->id)->get();
            if ($answers->count() < 2) {
                return ['duplicate' => false, 'completed' => false];
            }
            $winner = $this->roundWinner($s->game_type, $answers->sortBy(fn ($a) => $a->user_id === $s->created_by ? 0 : 1)->values(), $round);
            DB::table('game_rounds')->where('id', $round->id)->update(['winner' => $winner, 'completed_at' => now(), 'updated_at' => now()]);
            $state = $s->state ?? [];
            $state['round_wins'] = $state['round_wins'] ?? [];
            if (in_array($winner, ['player_1', 'player_2'], true)) {
                $state['round_wins'][$winner] = ($state['round_wins'][$winner] ?? 0) + 1;
            }
            $finished = $s->game_type === GameType::RockPaperScissors ? max($state['round_wins'] ?: [0]) >= 2 : $s->current_round >= (int) config('social.game_rounds', 10);
            if ($finished) {
                $s->update(['status' => GameStatus::Completed, 'state' => $state]);
                $this->settleCompleted($s, $winner);
            } else {
                $s->update(['current_round' => $s->current_round + 1, 'state' => $state]);
                $this->ensureRound($s);
            }

            if (! $finished) {
                $this->notifyOpen($s->fresh());
            }

            return ['duplicate' => false, 'completed' => $finished, 'winner' => $winner];
        });
    }

    private function settle(GameSession $session, ?string $winner): void
    {
        $players = $session->participants()->get();
        $settlement = [];
        $deltas = [];
        $winningUser = null;
        foreach ($players as $player) {
            $stats = DB::table('game_player_stats')->where('user_id', $player->id)->lockForUpdate()->first();
            if (! $stats) {
                DB::table('game_player_stats')->insert(['user_id' => $player->id, 'games_played' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'current_streak' => 0, 'best_streak' => 0, 'xp' => 0, 'competitive_score' => 1000, 'created_at' => now(), 'updated_at' => now()]);
                $stats = DB::table('game_player_stats')->where('user_id', $player->id)->lockForUpdate()->first();
            }
            $isWinner = $session->game_type === GameType::RockPaperScissors ? $winner === ($player->id === $session->created_by ? 'player_1' : 'player_2') : $winner === 'player_'.$player->id;
            if ($session->game_type === GameType::GuessNumber) {
                $isWinner = (int) ($session->state['gn_result']['winner_id'] ?? 0) === $player->id;
            }
            $isDraw = $winner === 'draw';
            $xp = $isWinner ? 25 : 10;
            if ($session->game_type === GameType::GuessNumber) {
                $xp = (int) config($isWinner ? 'guess_number.completion_xp' : 'guess_number.loser_xp', $isWinner ? 25 : 10);
                $session->participants()->updateExistingPivot($player->id, ['score' => (int) $isWinner, 'xp_earned' => $xp]);
            }
            $score = $session->game_type->value === GameType::ThisOrThat->value ? 0 : ($isWinner ? 20 : ($isDraw ? 0 : -10));
            $after = max(0, $stats->competitive_score + $score);
            DB::table('game_player_stats')->where('user_id', $player->id)->update(['games_played' => $stats->games_played + 1, 'wins' => $stats->wins + (int) $isWinner, 'losses' => $stats->losses + (int) (! $isWinner && ! $isDraw), 'draws' => $stats->draws + (int) $isDraw, 'current_streak' => $isWinner ? $stats->current_streak + 1 : 0, 'best_streak' => max($stats->best_streak, $isWinner ? $stats->current_streak + 1 : 0), 'xp' => $stats->xp + (is_array($xp) ? (int) ($xp[$player->id] ?? 0) : $xp), 'competitive_score' => $after, 'updated_at' => now()]);
            $delta = $after - $stats->competitive_score;
            $deltas[$player->id] = $delta;
            $settlement[$player->id] = ['result' => $isWinner ? 'Win' : ($isDraw ? 'Draw' : 'Loss'), 'xp' => $xp, 'rating_delta' => $delta];
            if ($isWinner) {
                $winningUser = $player->id;
            }
            $session->participants()->updateExistingPivot($player->id, ['xp_earned' => $xp]);
            if ($session->game_type !== GameType::GuessNumber && $delta !== 0) {
                DB::table('game_rating_events')->insert(['user_id' => $player->id, 'game_session_id' => $session->id, 'delta' => $delta, 'score_after' => $after, 'created_at' => now()]);
            }
        }
        if ($session->game_type === GameType::GuessNumber && $winningUser) {
            $winnerStats = $this->stats(User::findOrFail($winningUser));
            DB::table('game_rating_events')->insert(['user_id' => $winningUser, 'game_session_id' => $session->id, 'delta' => $deltas[$winningUser], 'score_after' => $winnerStats->competitive_score, 'participant_deltas' => json_encode($deltas), 'created_at' => now()]);
        }
        $st = $session->fresh()->state;
        $st['settlement'] = $settlement;
        $st['winner_user_id'] = $winningUser;
        $session->update(['state' => $st]);
        app(GameClosureService::class)->finished($session->fresh());
    }

    public function settleCompleted(GameSession $session, string $winner): void
    {
        DB::transaction(function () use ($session, $winner) {
            $locked = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            $players = $locked->participants()->get();
            if ($locked->status !== GameStatus::Completed || $locked->expires_at?->isPast() || $players->count() !== 2 || $players->contains(fn ($p) => $p->status !== UserStatus::Active) || $this->blocks->isBlocked($players[0], $players[1])) {
                throw new \DomainException('This game cannot grant rewards.');
            }

            if ($locked->state['rewards_settled'] ?? false) {
                return;
            } $state = $locked->state ?? [];
            $state['rewards_settled'] = true;
            $locked->update(['state' => $state]);
            $this->settle($locked, $winner);
        });
    }

    public function settleSocial(GameSession $session, int|array $xp): void
    {
        DB::transaction(function () use ($session, $xp) {
            $locked = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            $players = $locked->participants()->get();
            if ($locked->status !== GameStatus::Completed || $locked->expires_at?->isPast() || $players->count() !== 2 || $players->contains(fn ($p) => $p->status !== UserStatus::Active) || $this->blocks->isBlocked($players[0], $players[1])) {
                throw new \DomainException('This game cannot grant rewards.');
            }

            if ($locked->state['rewards_settled'] ?? false) {
                return;
            }
            $state = $locked->state ?? [];
            $state['rewards_settled'] = true;
            $locked->update(['state' => $state]);
            foreach ($locked->participants()->get() as $player) {
                $stats = $this->stats($player);
                DB::table('game_player_stats')->where('user_id', $player->id)->update(['games_played' => $stats->games_played + 1, 'xp' => $stats->xp + (is_array($xp) ? (int) ($xp[$player->id] ?? 0) : $xp), 'updated_at' => now()]);
                $earned = is_array($xp) ? (int) ($xp[$player->id] ?? 0) : $xp;
                $state['settlement'][$player->id] = ['result' => 'Completed together', 'xp' => $earned];
                $locked->participants()->updateExistingPivot($player->id, ['xp_earned' => $earned]);
            }
            $locked->update(['state' => $state]);
            app(GameClosureService::class)->finished($locked->fresh());
        });
    }

    public function stats(User $user): object
    {
        $row = DB::table('game_player_stats')->where('user_id', $user->id)->first();
        if ($row) {
            return $row;
        } DB::table('game_player_stats')->insert(['user_id' => $user->id, 'competitive_score' => 1000, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('game_player_stats')->where('user_id', $user->id)->first();
    }

    public function leaderboard(int $limit = 10): array
    {
        return array_map(fn ($r) => (object) ['display_name' => $r['display_name'], 'competitive_score' => $r['score']], array_slice(app(RankingService::class)->leaderboard(null)['rows'], 0, $limit));
    }

    public function leaderboardPeriod(string $period, ?int $cityId = null, ?User $viewer = null, int $limit = 10): array
    {
        return array_map(fn ($r) => (object) $r, array_slice(app(RankingService::class)->leaderboard($viewer, $period)['rows'], 0, $limit));
    }

    public function question(GameSession $session): ?array
    {
        if (in_array($session->game_type, [GameType::TwoTruthsOneLie, GameType::GuessInterest, GameType::GuessNumber], true)) {
            return match ($session->game_type) {
                GameType::TwoTruthsOneLie => app(TwoTruthsService::class)->guessQuestion($session),
                GameType::GuessInterest => app(GuessInterestService::class)->question($session),
                GameType::GuessNumber => app(GuessNumberService::class)->view($session->participants()->first(), $session),
            };
        }
        $round = DB::table('game_rounds')->where(['game_session_id' => $session->id, 'round_number' => $session->current_round])->first();
        if (! $round) {
            return null;
        }
        $prompt = json_decode($round->prompt, true) ?: [];
        unset($prompt['correct_option'], $prompt['secret']);

        return $prompt;
    }

    public function expireStale(): int
    {
        $count = DB::transaction(function () {
            $ids = GameSession::whereIn('status', [GameStatus::Waiting, GameStatus::Accepted, GameStatus::Active])
                ->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhere(fn ($q) => $q->whereNull('expires_at')->where('updated_at', '<=', now()->subHours(24))))->lockForUpdate()->pluck('id');

            return GameSession::whereIn('id', $ids)->update(['status' => GameStatus::Expired, 'updated_at' => now()]);
        });
        InteractionState::where('mode', 'like', 'game_%')->whereIn('game_context->session_id', GameSession::whereIn('status', [GameStatus::Expired, GameStatus::Cancelled, GameStatus::Completed])->selectRaw('CAST(id AS text)'))->update(['mode' => 'menu', 'game_context' => null]);

        return $count;
    }

    public function completeDailyChallenge(User $user): bool
    {
        if (! DB::table('daily_game_answers')->where(['user_id' => $user->id, 'challenge_date' => now(config('app.timezone'))->toDateString()])->exists()) {
            return false;
        }
        $inserted = DB::table('daily_challenge_completions')->insertOrIgnore(['user_id' => $user->id, 'challenge_date' => now(config('app.timezone'))->toDateString(), 'xp_awarded' => 10, 'created_at' => now(), 'updated_at' => now()]);
        if ($inserted) {
            $this->stats($user);
            DB::table('game_player_stats')->where('user_id', $user->id)->increment('xp', 10);
        }

        return (bool) $inserted;
    }

    public function expressInterest(User $user, GameSession $session): bool
    {
        return app(GameClosureService::class)->interest($user, $session);
    }

    public function assertPlayable(User $user, GameSession $session): void
    {
        $s = $session->fresh();
        try {
            app(GameClosureService::class)->participants($user, $s, false);
        } catch (\DomainException $e) {
            if ($s->participants()->whereKey($user->id)->exists() && $s->status !== GameStatus::Completed) {
                $s->update(['status' => GameStatus::Cancelled]);
            }
            throw $e;
        }
        if ($s->status === GameStatus::Active && $s->expires_at?->isPast()) {
            $s->update(['status' => GameStatus::Expired]);
        }
        if ($s->fresh()->status !== GameStatus::Active) {
            throw new \DomainException('This game is no longer active.');
        }
    }

    public function notifyOpen(GameSession $s): void
    {
        foreach ($s->participants()->get() as $user) {
            $this->notifications->queue($user, 'game_turn', $s->id, __('Your game is ready. Open the current round.'), 'game_turn:'.$s->id.':'.$s->current_round.':'.$user->id,
                [[['text' => __('Open game'), 'callback_data' => 'd:0:game_open_'.$s->id]]]);
        }
    }

    private function prompt(GameType $type, ?GameSession $session = null): array
    {
        if (in_array($type, [GameType::SpeedQuiz, GameType::ThisOrThat], true)) {
            $question = DB::table('quiz_questions')->where('is_active', true)->when($type === GameType::ThisOrThat, fn ($q) => $q->where('category', 'this_or_that'))->inRandomOrder()->first();
            if ($question) {
                return ['question_id' => $question->id, 'question' => $question->question, 'options' => json_decode($question->options, true), 'correct_option' => $question->correct_option, 'opened_at' => now()->toIso8601String()];
            }
        }

        return match ($type) {
            GameType::RockPaperScissors => ['choices' => ['rock', 'paper', 'scissors']], GameType::GuessNumber => ['range' => [1, 100], 'secret' => random_int(1, 100)], default => ['type' => $type->value]
        };
    }

    private function roundWinner(GameType $type, $answers, ?object $round = null): string
    {
        if ($type === GameType::SpeedQuiz || $type === GameType::ThisOrThat) {
            return 'draw';
        }
        if ($type !== GameType::RockPaperScissors) {
            return 'draw';
        } $a = strtolower($answers[0]->answer);
        $b = strtolower($answers[1]->answer);
        if ($a === $b) {
            return 'draw';
        } $wins = ['rock' => 'scissors', 'scissors' => 'paper', 'paper' => 'rock'];

        return ($wins[$a] ?? '') === $b ? 'player_1' : 'player_2';
    }
}
