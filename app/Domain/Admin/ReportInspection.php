<?php

namespace App\Domain\Admin;

use App\Domain\Moderation\Report;
use Illuminate\Support\Facades\DB;

class ReportInspection
{
    public function content(AdminUser $a, Report $report): string
    {
        app(AdminAuthorization::class)->authorize($a, 'moderation');
        if ($report->chat_message_id) {
            $m = DB::table('chat_messages')->where('id', $report->chat_message_id)->where('sender_user_id', $report->reported_user_id)->whereExists(fn ($q) => $q->from('conversation_participants')->whereColumn('conversation_id', 'chat_messages.conversation_id')->where('user_id', $report->reporter_user_id))->first();

            return $m ? mb_substr($m->text ?? __('[Media attachment; content not embedded]'), 0, 4000) : __('Referenced content unavailable.');
        }
        if ($report->direct_message_id) {
            $m = DB::table('direct_messages')->where('id', $report->direct_message_id)->where('sender_user_id', $report->reported_user_id)->where('recipient_user_id', $report->reporter_user_id)->first();

            return $m ? mb_substr($m->text, 0, 4000) : __('Referenced content unavailable.');
        }

        return __('Profile/game report: review the description and user reference. Game secrets are not displayed.');
    }
}
