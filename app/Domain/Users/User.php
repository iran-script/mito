<?php

namespace App\Domain\Users;

use App\Domain\Payments\Wallet;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\RegistrationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'is_bot' => false];

    protected $hidden = ['telegram_user_id', 'telegram_username', 'telegram_first_name', 'telegram_last_name', 'telegram_language_code'];

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (! $user->public_mito_id) {
                do {
                    $user->public_mito_id = MitoId::generate();
                } while (self::where('public_mito_id', $user->public_mito_id)->exists());
            }
            $user->public_mito_id = MitoId::normalize($user->public_mito_id)
                ?? throw new \InvalidArgumentException('Invalid public Mito ID.');
        });
    }

    public static function findByMitoId(string $value): ?self
    {
        $id = MitoId::normalize($value);

        return $id ? self::where('public_mito_id', $id)->first() : null;
    }

    protected function casts(): array
    {
        return ['status' => UserStatus::class, 'is_bot' => 'boolean', 'last_activity_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function registration(): HasOne
    {
        return $this->hasOne(RegistrationState::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_blocks', 'blocker_user_id', 'blocked_user_id')->withPivot('created_at');
    }

    public function blockedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_blocks', 'blocked_user_id', 'blocker_user_id')->withPivot('created_at');
    }
}
