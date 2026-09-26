<?php

namespace App\Domain\Admin;

use App\Domain\Events\Event;
use App\Domain\Events\EventService;
use App\Domain\Moderation\ReportStatus;
use App\Domain\Moderation\RestrictionService;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Facades\DB;

class ModerationService
{
    public function __construct(private AdminAuthorization $auth, private AdminAudit $audit) {}

    public function status(AdminUser $a, User $user, UserStatus $status, string $reason): void
    {
        $this->auth->authorize($a, 'moderation');
        $reason = $this->auth->reason($reason);
        if (! in_array($status, [UserStatus::Active, UserStatus::Suspended, UserStatus::Banned], true)) {
            throw new \DomainException('Unsupported moderation status.');
        }
        DB::transaction(function () use ($a, $user, $status, $reason) {
            $u = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = $u->status->value;
            if ($u->status === UserStatus::Deleted) {
                throw new \DomainException('Deleted accounts cannot be restored here.');
            }
            if ($u->status === $status) {
                return;
            }
            $u->update(['status' => $status]);
            if ($status !== UserStatus::Active) {
                InteractionState::where('user_id', $u->id)->update(['mode' => 'menu', 'game_context' => null, 'event_context' => null, 'bulk_context' => null, 'bulk_selection' => null, 'conversation_id' => null, 'direct_recipient_id' => null]);
            }
            $log = $this->audit->record($a, match ($status) {
                UserStatus::Active => 'user.restore',UserStatus::Suspended => 'user.suspend',default => 'user.ban'
            }, 'user', $u->id, $reason, ['before' => $before, 'after' => $status->value]);
            app(SocialNotificationService::class)->queue($u, 'account_moderation', $log->id, $status === UserStatus::Active ? __('Your account has been restored.') : __('Your account is unavailable. Please contact support.'), 'account_moderation:'.$log->id);
        });
    }

    public function report(AdminUser $a, int $id, ReportStatus $status, string $reason, ?string $note = null, ?int $assigned = null, bool $event = false): void
    {
        $this->auth->authorize($a, 'moderation');
        $reason = $this->auth->reason($reason);
        if ($note !== null && mb_strlen($note) > 5000) {
            throw new \DomainException('Note is too long.');
        }
        if ($assigned && ! AdminUser::whereKey($assigned)->where('is_active', true)->whereIn('role', ['super_admin', 'moderator'])->exists()) {
            throw new \DomainException('Assignee must be an active moderator.');
        }
        DB::transaction(function () use ($a, $id, $status, $reason, $note, $assigned, $event) {
            $table = $event ? 'event_reports' : 'reports';
            $r = DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table($table)->where('id', $id)->update(['status' => $status->value, 'internal_notes' => $note, 'assigned_admin_id' => $assigned, 'updated_at' => now()]);
            $this->audit->record($a, 'report.'.$status->value, $table, $id, $reason, ['before' => $r->status, 'after' => $status->value, 'assigned_admin_id' => $assigned, 'note_changed' => $note !== $r->internal_notes]);
        });
    }

    public function restrict(AdminUser $a, User $user, string $type, string $reason, ?\DateTimeInterface $ends = null, ?\DateTimeInterface $starts = null): int
    {
        $this->auth->authorize($a, 'moderation');
        $reason = $this->auth->reason($reason);
        $starts ??= now();
        if (! in_array($type, RestrictionService::TYPES, true) || ($ends && $ends <= $starts)) {
            throw new \DomainException('Invalid restriction or time window.');
        }

        return DB::transaction(function () use ($a, $user, $type, $reason, $starts, $ends) {
            $id = DB::table('user_restrictions')->insertGetId(['user_id' => $user->id, 'restriction_type' => $type, 'starts_at' => $starts, 'ends_at' => $ends, 'reason' => $reason, 'admin_user_id' => $a->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record($a, 'restriction.create', 'user_restriction', $id, $reason, ['user_id' => $user->id, 'type' => $type]);

            return $id;
        });
    }

    public function lift(AdminUser $a, int $id, string $reason): void
    {
        $this->auth->authorize($a, 'moderation');
        $reason = $this->auth->reason($reason);
        DB::transaction(function () use ($a, $id, $reason) {
            $r = DB::table('user_restrictions')->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table('user_restrictions')->where('id', $id)->update(['ends_at' => now(), 'updated_at' => now()]);
            $this->audit->record($a, 'restriction.lift', 'user_restriction', $r->id, $reason);
        });
    }

    public function cancelEvent(AdminUser $a, Event $event, string $reason): void
    {
        $this->auth->authorize($a, 'moderation');
        $reason = $this->auth->reason($reason);
        DB::transaction(function () use ($a, $event, $reason) {
            $e = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($e->status->value === 'cancelled') {
                return;
            }app(EventService::class)->cancel($e->creator, $e);
            $this->audit->record($a, 'event.cancel', 'event', $e->id, $reason);
        });
    }
}
