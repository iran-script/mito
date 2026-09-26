<?php

namespace App\Domain\Admin;

use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \DomainException('Audit history is immutable.'));
        static::deleting(fn () => throw new \DomainException('Audit history is immutable.'));
    }
}
