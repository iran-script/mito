<?php

namespace App\Providers;

use App\Domain\Payments\PaidActionGate;
use App\Domain\Payments\WalletPaidActionGate;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfilePolicy;
use App\Domain\Telegram\HttpTelegramClient;
use App\Domain\Telegram\TelegramClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TelegramClient::class, HttpTelegramClient::class);
        $this->app->bind(PaidActionGate::class, WalletPaidActionGate::class);
    }

    public function boot(): void
    {
        Gate::policy(Profile::class, ProfilePolicy::class);
    }
}
