<?php

namespace App\Domain\Games;

use App\Domain\Chat\ChatRequest;
use App\Domain\Chat\ChatRequestService;
use App\Domain\Contacts\ContactService;
use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportService;
use App\Domain\Payments\WalletService;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use App\Support\Presentation;
use Illuminate\Support\Facades\DB;

class GameClosureService
{
    public function participants(User $actor, GameSession $session, bool $completed = true): array
    {
        $s = $session->fresh();
        $players = $s->participants()->get();
        if (($completed && $s->status !== GameStatus::Completed) || $players->count() !== 2 || ! $players->contains('id', $actor->id)
            || $players->contains(fn ($p) => $p->status !== UserStatus::Active || $p->profile?->status !== ProfileStatus::Active)
            || app(BlockService::class)->isBlocked($players[0], $players[1])) {
            throw new \DomainException('This game is unavailable.');
        }

        return [$players->firstWhere('id', $actor->id), $players->first(fn ($p) => $p->id !== $actor->id)];
    }

    public function rematch(User $actor, GameSession $session): GameSession
    {
        return DB::transaction(function () use ($actor, $session) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            [, $other] = $this->participants($actor, $s);
            if (isset($s->state['rematch_id'])) {
                return GameSession::findOrFail($s->state['rematch_id']);
            }
            $new = app(GameService::class)->invite($actor, $other, $s->game_type);
            $st = $s->state;
            $st['rematch_id'] = $new->id;
            $s->update(['state' => $st]);

            return $new;
        });
    }

    public function contact(User $actor, GameSession $session): bool
    {
        [, $other] = $this->participants($actor, $session);

        return app(ContactService::class)->add($actor, $other)->wasRecentlyCreated;
    }

    public function chat(User $actor, GameSession $session): ChatRequest
    {
        return DB::transaction(function () use ($actor, $session) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            [, $other] = $this->participants($actor, $s);
            $old = $s->state['postgame_chat'][$actor->id] ?? null;
            if ($old) {
                return ChatRequest::findOrFail($old);
            }
            // Ensure legacy accounts cannot enter the paid gate's missing-wallet compatibility path.
            app(WalletService::class)->wallet($actor);
            $request = app(ChatRequestService::class)->create($actor, $other);
            $st = $s->state;
            $st['postgame_chat'][$actor->id] = $request->id;
            $s->update(['state' => $st]);
            app(SocialNotificationService::class)->request($other, $request->id);

            return $request;
        });
    }

    public function interest(User $actor, GameSession $session): bool
    {
        return DB::transaction(function () use ($actor, $session) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            [, $other] = $this->participants($actor, $s);
            DB::table('game_social_intents')->insertOrIgnore(['game_session_id' => $s->id, 'user_id' => $actor->id, 'intent' => 'interested', 'created_at' => now(), 'updated_at' => now()]);
            $mutual = DB::table('game_social_intents')->where('game_session_id', $s->id)->where('intent', 'interested')->whereIn('user_id', [$actor->id, $other->id])->count() === 2;
            if ($mutual && ! isset($s->state['mutual_match_at'])) {
                $st = $s->state;
                $st['mutual_match_at'] = now()->toIso8601String();
                $s->update(['state' => $st]);
                foreach ([$actor, $other] as $u) {
                    app(SocialNotificationService::class)->queue($u, 'game_mutual', $s->id, __('Both of you want to get to know each other 💛'), 'game_mutual:'.$s->id.':'.$u->id,
                        [[['text' => __('💬 Request Chat'), 'callback_data' => 'd:0:game_post_chat_'.$s->id]]]);
                }
            }

            return $mutual;
        });
    }

    public function report(User $actor, GameSession $session, ReportReason $reason): void
    {
        DB::transaction(function () use ($actor, $session, $reason) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            [, $other] = $this->participants($actor, $s);
            if (isset($s->state['postgame_report'][$actor->id])) {
                return;
            }
            $report = app(ReportService::class)->create($actor, $other, $reason, 'Game session #'.$s->id.' ('.$s->game_type->value.').');
            $st = $s->state;
            $st['postgame_report'][$actor->id] = $report->id;
            $s->update(['state' => $st]);
        });
    }

    public function result(User $user, GameSession $session): array
    {
        [$player, $opponent] = $this->participants($user, $session);
        $s = $session->fresh();
        $competitive = in_array($s->game_type, [GameType::RockPaperScissors, GameType::SpeedQuiz, GameType::GuessNumber], true);
        $record = $s->state['settlement'][$user->id] ?? [];
        if (! $record && $competitive) {
            $winner = $s->state['gn_result']['winner_id'] ?? $s->state['winner_user_id'] ?? null;
            $draw = false;
            if ($s->game_type === GameType::SpeedQuiz) {
                $scores = $s->state['scores'] ?? [];
                $draw = ($scores[$user->id] ?? 0) === ($scores[$opponent->id] ?? 0);
                $winner = ($scores[$user->id] ?? 0) > ($scores[$opponent->id] ?? 0) ? $user->id : $opponent->id;
            }
            if ($s->game_type === GameType::RockPaperScissors) {
                $wins = $s->state['round_wins'] ?? [];
                $winner = ($wins['player_1'] ?? 0) > ($wins['player_2'] ?? 0) ? $s->created_by : ($s->created_by === $user->id ? $opponent->id : $user->id);
            }
            $record = ['result' => $draw ? __('Draw') : ((int) $winner === $user->id ? __('Win') : __('Loss')), 'xp' => $player->pivot->xp_earned ?: ((int) $winner === $user->id && ! $draw ? 25 : 10)];
        }
        if (! $record && $s->game_type === GameType::ThisOrThat) {
            $record = ['result' => __('Completed together'), 'xp' => 15];
        }
        $name = match ($s->game_type) {
            GameType::TwoTruthsOneLie => __('Two Truths and a Lie'), default => Presentation::label(ucwords(str_replace('_', ' ', $s->game_type->value)))
        };
        $text = $name.__(" complete.\n");
        $result = $record['result'] ?? ($competitive ? __('Completed') : __('Completed together'));
        $text .= __('Result: ').Presentation::label($result);
        $score = match ($s->game_type) {
            GameType::GuessInterest => __('Correct guesses: ').($s->state['gi_result']['correct_guesses'][$user->id] ?? 0).__(' / Opponent: ').($s->state['gi_result']['correct_guesses'][$opponent->id] ?? 0),
            GameType::TwoTruthsOneLie => __('Correct guesses: ').($s->state['tt_result']['correct_guesses'][$user->id] ?? 0),
            GameType::ThisOrThat => __('Compatibility: ').($s->state['compatibility'] ?? 0).'% ('.($s->state['same_answers'] ?? 0).__(' matching answers)'),
            GameType::SpeedQuiz => __('Score: ').($s->state['scores'][$user->id] ?? 0).__(' / Opponent: ').($s->state['scores'][$opponent->id] ?? 0),
            GameType::RockPaperScissors => __('Rounds won: ').($s->state['round_wins'][$user->id === $s->created_by ? 'player_1' : 'player_2'] ?? 0),
            GameType::GuessNumber => __('Number: ').($s->state['gn_result']['secret'] ?? '').'. '.(($s->state['gn_result']['winner_id'] ?? 0) === $user->id ? __('Correct! You win.') : __('Your opponent wins.')),
        };
        $text .= "\n".$score.__("\nXP earned: +").($record['xp'] ?? $player->pivot->xp_earned);
        if ($competitive) {
            $delta = $record['rating_delta'] ?? $this->legacyDelta($user, $s);
            $text .= __("\nRating change: ").($delta >= 0 ? '+' : '').$delta;
        }
        $rank = app(RankingService::class)->rank(app(GameService::class)->stats($user)->competitive_score);
        $text .= __("\nCurrent rank: :v1 (:v2)", ['v1' => Presentation::label($rank['tier']), 'v2' => $rank['rating']]);

        return ['text' => $text, 'keyboard' => $this->buttons($s)];
    }

    private function legacyDelta(User $user, GameSession $s): int
    {
        $sum = 0;
        foreach (DB::table('game_rating_events')->where('game_session_id', $s->id)->get() as $event) {
            $d = $event->participant_deltas ? json_decode($event->participant_deltas, true) : [$event->user_id => $event->delta];
            $sum += $d[$user->id] ?? 0;
        }

        return $sum;
    }

    public function buttons(GameSession $s): array
    {
        $labels = ['rematch' => __('🔁 Play Again'), 'contact' => __('➕ Add to Contacts'), 'chat' => __('💬 Request Chat'), 'interest' => __('💛 Get to Know Each Other'), 'report' => __('🚩 Report')];
        $rows = [];
        foreach ($labels as $action => $label) {
            $rows[] = [['text' => $label, 'callback_data' => 'd:0:game_post_'.$action.'_'.$s->id]];
        }
        $rows[] = [['text' => __('🎮 Back to Games'), 'callback_data' => 'd:0:games']];

        return $rows;
    }

    public function finished(GameSession $session): void
    {
        foreach ($session->participants()->get() as $user) {
            app(GameProgressionService::class)->award($user);
            $result = $this->result($user, $session);
            app(SocialNotificationService::class)->queue($user, 'game_result', $session->id, $result['text'], 'game_result:'.$session->id.':'.$user->id, $result['keyboard']);
        }
        InteractionState::where('mode', 'like', 'game_%')->where('game_context->session_id', $session->id)->update(['mode' => 'menu', 'game_context' => null]);
    }
}
