<?php

namespace App\Filament\Resources;

use App\Domain\Games\Badge;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;

class BadgeResource extends OperationalResource
{
    protected static ?string $model = Badge::class;

    protected static string $area = 'operations';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('code'), T::make('name'), T::make('description')->wrap()])->filters([])->recordActions([])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBadge::route('/')];
    }
}
