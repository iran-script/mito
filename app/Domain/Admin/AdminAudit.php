<?php

namespace App\Domain\Admin;

class AdminAudit
{
    public function record(AdminUser $admin, string $action, string $subject, ?int $id, string $reason, array $metadata = []): AdminAuditLog
    {
        return AdminAuditLog::create(['admin_user_id' => $admin->id, 'action' => $action, 'subject_type' => $subject, 'subject_id' => $id, 'reason' => $reason, 'metadata' => $metadata, 'created_at' => now()]);
    }
}
