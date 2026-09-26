<?php

namespace App\Domain\Events;

use App\Domain\Profiles\City;
use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => EventStatus::class, 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'location_updated_at' => 'immutable_datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function category()
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'event_participants')->withPivot(['status', 'joined_at', 'left_at'])->withTimestamps();
    }

    public function isJoinable(): bool
    {
        return $this->status === EventStatus::Published && $this->starts_at->isFuture();
    }
}
