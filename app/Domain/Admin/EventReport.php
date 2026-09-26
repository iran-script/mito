<?php

namespace App\Domain\Admin;

use Illuminate\Database\Eloquent\Model;

class EventReport extends Model
{
    protected $table = 'event_reports';

    protected $guarded = ['id'];

    protected $hidden = ['internal_notes', 'assigned_admin_id'];

    protected function casts(): array
    {
        return [];
    }
}
