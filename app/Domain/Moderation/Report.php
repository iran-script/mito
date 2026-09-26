<?php

namespace App\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['internal_notes', 'assigned_admin_id'];

    protected function casts(): array
    {
        return ['reason' => ReportReason::class, 'status' => ReportStatus::class];
    }
}
