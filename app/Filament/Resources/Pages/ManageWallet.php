<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\WalletResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWallet extends ManageRecords
{
    protected static string $resource = WalletResource::class;
}
