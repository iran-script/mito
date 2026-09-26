<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\GoldMembershipResource;
use Filament\Resources\Pages\ManageRecords;

class ManageGoldMembership extends ManageRecords
{
    protected static string $resource = GoldMembershipResource::class;
}
