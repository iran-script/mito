<?php

namespace App\Domain\Admin;

use Illuminate\Auth\Access\AuthorizationException;

class AdminAuthorization
{
    public function allows(?AdminUser $admin, string $area): bool
    {
        $a = $admin?->fresh();

        return $a && $a->is_active && ($a->role === 'super_admin' || ($a->role === 'moderator' && in_array($area, ['moderation', 'operations'], true)) || ($a->role === 'finance_admin' && $area === 'finance'));
    }

    public function authorize(?AdminUser $admin, string $area): void
    {
        if (! $this->allows($admin, $area)) {
            throw new AuthorizationException('This administration action is not authorized.');
        }
    }

    public function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new \DomainException('A reason of 1–2000 characters is required.');
        }

        return $reason;
    }
}
