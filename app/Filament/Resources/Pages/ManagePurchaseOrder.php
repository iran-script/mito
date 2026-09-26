<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePurchaseOrder extends ManageRecords
{
    protected static string $resource = PurchaseOrderResource::class;
}
