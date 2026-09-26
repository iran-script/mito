<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\GameSessionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageGameSession extends ManageRecords
{
    protected static string $resource = GameSessionResource::class;
}
