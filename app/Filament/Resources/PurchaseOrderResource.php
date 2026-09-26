<?php

namespace App\Filament\Resources;

use App\Domain\Payments\PurchaseOrder;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;

class PurchaseOrderResource extends OperationalResource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string $area = 'finance';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('id'), T::make('user_id')->searchable(), T::make('package_id'), T::make('base_coins_snapshot'), T::make('bonus_coins_snapshot'), T::make('amount_snapshot'), T::make('currency_snapshot'), T::make('status'), T::make('provider'), T::make('provider_reference'), T::make('created_at')->dateTime('Y/m/d H:i'), T::make('paid_at')->dateTime('Y/m/d H:i')])->filters([])->recordActions([])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePurchaseOrder::route('/')];
    }
}
