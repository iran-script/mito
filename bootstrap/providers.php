<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\LocalizationServiceProvider;

return [
    AppServiceProvider::class,
    LocalizationServiceProvider::class,
    AdminPanelProvider::class,
];
