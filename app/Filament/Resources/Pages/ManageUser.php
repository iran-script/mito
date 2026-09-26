<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\ManageRecords;

class ManageUser extends ManageRecords
{
    protected static string $resource = UserResource::class;
}
