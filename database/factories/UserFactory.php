<?php

namespace Database\Factories;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return ['telegram_user_id' => fake()->unique()->numberBetween(1, 2147483647), 'telegram_first_name' => fake()->firstName()];
    }
}
