<?php

namespace App\Domain\Memberships;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => MembershipStatus::class, 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function active(): bool
    {
        return $this->status === MembershipStatus::Active && $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
