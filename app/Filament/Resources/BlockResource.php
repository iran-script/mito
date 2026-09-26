<?php

namespace App\Filament\Resources;

use App\Domain\Users\UserBlock;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;

class BlockResource extends OperationalResource
{
    protected static ?string $model = UserBlock::class;

    protected static string $area = 'moderation';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('blocker_user_id'), T::make('blocked_user_id'), T::make('created_at')->dateTime('Y/m/d H:i')])->filters([])->recordActions([])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBlock::route('/')];
    }
}
