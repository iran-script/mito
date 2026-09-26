<?php

namespace App\Console\Commands;

use App\Domain\Admin\AdminUser;
use Illuminate\Console\Command;

class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create {--role=super_admin : super_admin, moderator or finance_admin}';

    protected $description = 'Create an active administration user';

    public function handle(): int
    {
        $role = $this->option('role');
        if (! in_array($role, ['super_admin', 'moderator', 'finance_admin'], true)) {
            $this->error('Invalid role.');

            return self::FAILURE;
        }
        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');
        if (! $name || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $password || strlen($password) < 12 || $password !== $confirmation) {
            $this->error('Valid matching credentials are required.');

            return self::FAILURE;
        } if (AdminUser::where('email', $email)->exists()) {
            $this->error('Email already exists.');

            return self::FAILURE;
        } AdminUser::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true, 'role' => $role]);
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
