<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\TransactionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageTransaction extends ManageRecords
{
    protected static string $resource = TransactionResource::class;
}
