<?php

namespace App\Domain\Admin;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class AdminUser extends Authenticatable implements FilamentUser
{
    use Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        $admin = $this->fresh();

        return $panel->getId() === 'admin' && $admin?->is_active && in_array($admin->role, ['super_admin', 'moderator', 'finance_admin'], true);
    }

    protected $table = 'admin_users';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'password' => 'hashed'];
    }
}
