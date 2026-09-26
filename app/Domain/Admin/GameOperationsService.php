<?php

namespace App\Domain\Admin;

use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\SocialNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GameOperationsService
{
    public function __construct(private AdminAuthorization $auth, private AdminAudit $audit) {}

    public function toggle(AdminUser $a, string $type, bool $enabled, string $reason): void
    {
        $this->auth->authorize($a, 'operations');
        $reason = $this->auth->reason($reason);
        if ($type !== 'daily_challenge' && ! GameType::tryFrom($type)) {
            throw new \DomainException('Invalid game type.');
        }
        DB::transaction(function () use ($a, $type, $enabled, $reason) {
            DB::table('economy_settings')->updateOrInsert(['key' => 'game_enabled_'.$type], ['value' => $enabled ? '1' : '0', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record($a, 'game.toggle', 'game_type', null, $reason, ['type' => $type, 'enabled' => $enabled]);
        });
    }

    public function cancel(AdminUser $a, GameSession $session, string $reason): void
    {
        $this->auth->authorize($a, 'operations');
        $reason = $this->auth->reason($reason);
        DB::transaction(function () use ($a, $session, $reason) {
            $s = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->status === GameStatus::Cancelled) {
                return;
            }
            if (! in_array($s->status, [GameStatus::Waiting, GameStatus::Accepted, GameStatus::Active], true)) {
                throw new \DomainException('Only an unfinished game can be cancelled.');
            }
            $s->update(['status' => GameStatus::Cancelled]);
            foreach ($s->participants()->get() as $u) {
                InteractionState::where('user_id', $u->id)->where('game_context->session_id', $s->id)->update(['mode' => 'menu', 'game_context' => null]);
                app(SocialNotificationService::class)->queue($u, 'game_cancelled', $s->id, __('This game was cancelled by support. No rewards were awarded.'), 'game_admin_cancel:'.$s->id.':'.$u->id);
            }
            $this->audit->record($a, 'game.cancel', 'game_session', $s->id, $reason);
        });
    }

    public function question(AdminUser $a, ?int $id, array $data, bool $social, string $reason): int
    {
        $this->auth->authorize($a, 'operations');
        $reason = $this->auth->reason($reason);
        $v = Validator::make($data, ['question' => 'required|string|max:1000', 'options' => 'required|array|min:2|max:6', 'options.*' => 'required|string|max:100', 'correct_option' => $social ? 'nullable' : 'required|string|max:100', 'is_active' => 'required|boolean', 'difficulty' => 'sometimes|integer|min:1|max:5'])->validate();
        $v['question'] = trim($v['question']);
        $v['options'] = array_map('trim', array_values($v['options']));
        if ($v['question'] === '' || in_array('', $v['options'], true) || count(array_unique(array_map('mb_strtolower', $v['options']))) !== count($v['options']) || ($social && count($v['options']) !== 2) || (! $social && ! in_array($v['correct_option'], $v['options'], true))) {
            throw new \DomainException('Options must be distinct and quiz must have exactly one correct option.');
        }
        $v['correct_option'] = $social ? '' : $v['correct_option'];
        $v['category'] = $social ? 'this_or_that' : 'general';
        $v['options'] = json_encode($v['options'], JSON_THROW_ON_ERROR);
        $v['updated_at'] = now();

        return DB::transaction(function () use ($a, $id, $v, $social, $reason) {
            if ($id) {
                $old = DB::table('quiz_questions')->where('id', $id)->lockForUpdate()->firstOrFail();
                if (($old->category === 'this_or_that') !== $social) {
                    throw new \DomainException('Question type cannot change.');
                }DB::table('quiz_questions')->where('id', $id)->update($v);
            } else {
                $id = DB::table('quiz_questions')->insertGetId($v + ['created_at' => now()]);
            }$this->audit->record($a, 'question.save', 'quiz_question', $id, $reason, ['social' => $social, 'active' => $v['is_active']]);

            return $id;
        });
    }
}
