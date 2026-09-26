<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAuditLog extends ManageRecords
{
    protected static string $resource = AuditLogResource::class;
}
