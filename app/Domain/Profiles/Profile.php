<?php

namespace App\Domain\Profiles;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Profile extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['user_id', 'birth_date', 'photo_file_id', 'voice_file_id', 'latitude', 'longitude', 'location_updated_at'];

    protected function casts(): array
    {
        return ['gender' => Gender::class, 'status' => ProfileStatus::class, 'birth_date' => 'immutable_date', 'profile_completed_at' => 'immutable_datetime', 'location_updated_at' => 'immutable_datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class)->withTimestamps();
    }
}
