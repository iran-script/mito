<?php

namespace App\Domain\Moderation;

use App\Domain\Users\User;
use App\Domain\Users\UserStatus;

class ReportService
{
    public function create(User $reporter, User $reported, ReportReason $reason, ?string $description = null, ?int $chatMessageId = null, ?int $directMessageId = null): Report
    {
        if ($reporter->is($reported) || $reported->status !== UserStatus::Active) {
            throw new \DomainException('This user cannot be reported.');
        }

        return Report::create(['reporter_user_id' => $reporter->id, 'reported_user_id' => $reported->id, 'reason' => $reason, 'description' => $description ? mb_substr(trim($description), 0, 2000) : null, 'chat_message_id' => $chatMessageId, 'direct_message_id' => $directMessageId, 'status' => ReportStatus::Open]);
    }
}
