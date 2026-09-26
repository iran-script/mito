<?php

namespace App\Domain\Admin;

use Illuminate\Database\Eloquent\Model;

class QuizQuestion extends Model
{
    protected $table = 'quiz_questions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'is_active' => 'boolean'];
    }
}
