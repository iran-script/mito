<?php

namespace App\Domain\Profiles;

use Illuminate\Database\Eloquent\Model;

class RegistrationState extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['step' => 'name', 'revision' => 0];

    protected function casts(): array
    {
        return ['step' => RegistrationStep::class, 'selection_context' => 'array'];
    }
}
