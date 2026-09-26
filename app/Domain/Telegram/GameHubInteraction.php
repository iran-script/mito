<?php

namespace App\Domain\Telegram;

use App\Domain\Games\DailyChallengeService;
use App\Domain\Games\GameClosureService;
use App\Domain\Games\GameProgressionService;
use App\Domain\Games\GameSession;
use App\Domain\Games\RankingService;
use App\Domain\Moderation\ReportReason;
use App\Domain\Users\User;
use App\Support\Presentation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class GameHubInteraction
{
    public function handle(User $user, ?string $value): ?array
    {
        try {
            if ($value === 'game_leaderboard') {
                $rows = [];
                foreach (['daily' => __('Daily'), 'weekly' => __('Weekly'), 'monthly' => __('Monthly'), 'all' => __('All Time'), 'city' => __('City'), 'contacts' => __('Contacts')] as $period => $label) {
                    $rows[] = [$this->button($label, 'game_board_'.$period.'_1')];
                }

                return $this->message(__('🏆 Leaderboard — choose a view.'), $rows);
            }
            if (preg_match('/^game_board_(daily|weekly|monthly|all|city|contacts)_([0-9]+)$/', $value ?? '', $m)) {
                $board = app(RankingService::class)->leaderboard($user, $m[1], (int) $m[2]);
                $text = '🏆 '.($m[1] === 'all' ? __('All Time') : Presentation::label(ucfirst($m[1]))).__(" Leaderboard\n");
                foreach ($board['rows'] as $row) {
                    $row['tier'] = Presentation::label($row['tier']);
                    $text .= "{$row['position']}. {$row['display_name']} · {$row['tier']} · {$row['score']}\n";
                }
                if (! $board['rows']) {
                    $text .= __("No ranked players in this view.\n");
                }
                $own = $board['own'];
                if ($own) {
                    $own['tier'] = Presentation::label($own['tier']);
                }
                $text .= $own ? __("\nYour Rank: #:v1 · :v2 · :v3", ['v1' => $own['position'], 'v2' => $own['tier'], 'v3' => $own['score']]) : __("\nYour Rank: Not ranked in this view.");
                $text .= __("\nPage :v1 / :v2", ['v1' => $board['page'], 'v2' => $board['pages']]);
                $nav = [];
                if ($board['page'] > 1) {
                    $nav[] = $this->button(__('Previous'), 'game_board_'.$m[1].'_'.($board['page'] - 1));
                }
                if ($board['page'] < $board['pages']) {
                    $nav[] = $this->button(__('Next'), 'game_board_'.$m[1].'_'.($board['page'] + 1));
                }

                return $this->message($text, array_filter([$nav, [$this->button(__('Leaderboard views'), 'game_leaderboard')]]));
            }
            if (in_array($value, ['game_stats', 'game_rank'], true)) {
                $s = app(GameProgressionService::class)->stats($user);
                $r = $s['rank'];
                $r['tier'] = Presentation::label($r['tier']);
                $r['next_tier'] = Presentation::label($r['next_tier']);
                $text = __("📊 My Rank / Stats\n:v1\nRating: :v2\n", ['v1' => $r['tier'], 'v2' => $r['rating']]);
                $text .= $r['next_threshold'] === null ? __('Highest rank reached.') : __("Next: :v1 at :v2\n:v3 points remaining (:v4% progress)", ['v1' => $r['next_tier'], 'v2' => $r['next_threshold'], 'v3' => $r['remaining'], 'v4' => $r['progress']]);
                if ($value === 'game_stats') {
                    $text .= __("\nGames played: :v1\nWins: :v2\nLosses: :v3\nDraws: :v4\nWin rate: :v5%\nCurrent win streak: :v6\nBest win streak: :v7\nXP: :v8", ['v1' => $s['games_played'], 'v2' => $s['wins'], 'v3' => $s['losses'], 'v4' => $s['draws'], 'v5' => $s['win_rate'], 'v6' => $s['current_streak'], 'v7' => $s['best_streak'], 'v8' => $s['xp']]);
                    foreach (['rock_paper_scissors' => __('RPS'), 'speed_quiz' => __('Speed Quiz'), 'guess_number' => __('Guess Number')] as $type => $name) {
                        $text .= "\n".$name.__(' played: ').($s['per_game'][$type] ?? 0);
                    }
                    $text .= __("\nSocial games played: ").array_sum(array_intersect_key($s['per_game'], array_flip(['this_or_that', 'two_truths_one_lie', 'guess_interest'])));
                }

                return $this->message($text, [[$this->button($value === 'game_rank' ? __('My Stats') : __('My Rank'), $value === 'game_rank' ? 'game_stats' : 'game_rank')]]);
            }
            if ($value === 'game_badges') {
                $text = __("🎖 My Badges\n");
                $badges = app(GameProgressionService::class)->badges($user);
                foreach ($badges as $b) {
                    $b['name'] = Presentation::label($b['name']);
                    $b['description'] = Presentation::label($b['description']);
                    $text .= __("\n:v1 :v2\n:v3\nEarned: :v4\n", ['v1' => $b['icon'], 'v2' => $b['name'], 'v3' => $b['description'], 'v4' => $b['earned_date']]);
                }

                return $this->message($text.($badges ? '' : __('No badges yet. Complete games to earn achievements.')));
            }
            if (in_array($value, ['game_daily', 'game_daily_complete'], true)) {
                $q = app(DailyChallengeService::class)->question($user);
                if ($q['answered']) {
                    return $this->message(__('Today’s challenge is complete. Come back tomorrow.'));
                }
                $rows = [];
                foreach ($q['options'] as $index => $option) {
                    $rows[] = [$this->button(Presentation::label($option), 'game_daily_answer_'.str_replace('-', '', $q['date']).'_'.$index)];
                }

                return $this->message(__("🧠 Daily Challenge\n").Presentation::label($q['question']), $rows);
            }
            if (preg_match('/^game_daily_answer_([0-9]{8})_([0-9]+)$/', $value ?? '', $m)) {
                $r = app(DailyChallengeService::class)->answer($user, substr($m[1], 0, 4).'-'.substr($m[1], 4, 2).'-'.substr($m[1], 6, 2), (int) $m[2]);

                return $this->message($r['duplicate'] ? __('Already completed today. No additional XP.') : ($r['correct'] ? __('Correct!') : __('Incorrect.')).__("\nDaily Challenge complete. XP earned: +:v1", ['v1' => $r['xp']]));
            }
            if (preg_match('/^game_post_(result|rematch|contact|chat|interest|report)_([0-9]+)$/', $value ?? '', $m)) {
                $s = GameSession::findOrFail((int) $m[2]);
                $closure = app(GameClosureService::class);
                if ($m[1] === 'result') {
                    return $this->result($user, $s);
                }
                if ($m[1] === 'rematch') {
                    $closure->rematch($user, $s);

                    return $this->message(__('Rematch invitation sent. The previous result is preserved.'));
                }
                if ($m[1] === 'contact') {
                    return $this->message($closure->contact($user, $s) ? __('Added to your contacts.') : __('Already in your contacts.'));
                }
                if ($m[1] === 'chat') {
                    $closure->chat($user, $s);

                    return $this->message(__('Chat request sent. Normal paid acceptance rules apply. No conversation has been opened.'));
                }
                if ($m[1] === 'interest') {
                    return $this->message($closure->interest($user, $s) ? __('Both of you want to get to know each other 💛') : __('Your choice was saved privately.'), [[$this->button(__('Back to result'), 'game_post_result_'.$s->id)]]);
                }
                $closure->participants($user, $s);
                $rows = [];
                foreach (ReportReason::cases() as $reason) {
                    $rows[] = [$this->button(Presentation::label($reason->value), 'game_post_reason_'.$s->id.'_'.$reason->value)];
                }

                return $this->message(__('Choose a report reason:'), $rows);
            }
            if (preg_match('/^game_post_reason_([0-9]+)_([a-z_]+)$/', $value ?? '', $m)) {
                $reason = ReportReason::tryFrom($m[2]);
                if (! $reason) {
                    throw new \DomainException('Invalid report reason.');
                }
                app(GameClosureService::class)->report($user, GameSession::findOrFail((int) $m[1]), $reason);

                return $this->message(__('Report submitted.'));
            }
        } catch (\DomainException $e) {
            return $this->message(Presentation::error($e->getMessage()));
        } catch (ModelNotFoundException $e) {
            return $this->message(__('This game is unavailable.'));
        }

        return null;
    }

    public function result(User $user, GameSession $session): array
    {
        $result = app(GameClosureService::class)->result($user, $session);

        return [['method' => 'sendMessage', 'parameters' => ['text' => $result['text'], 'reply_markup' => ['inline_keyboard' => $result['keyboard']]]]];
    }

    private function button(string $text, string $action): array
    {
        return ['text' => $text, 'callback_data' => 'd:0:'.$action];
    }

    private function message(string $text, array $rows = []): array
    {
        $rows[] = [$this->button(__('🎮 Back to Games'), 'games')];

        return [['method' => 'sendMessage', 'parameters' => ['text' => $text, 'reply_markup' => ['inline_keyboard' => array_values($rows)]]]];
    }
}
