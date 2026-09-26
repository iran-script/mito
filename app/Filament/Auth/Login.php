<?php

namespace App\Filament\Auth;

class Login extends \Filament\Auth\Pages\Login
{
    protected function getCredentialsFromFormData(#[\SensitiveParameter] array $data): array
    {
        return parent::getCredentialsFromFormData($data) + ['is_active' => true];
    }
}
