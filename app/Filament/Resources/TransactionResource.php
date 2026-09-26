<?php

namespace App\Filament\Resources;

use App\Domain\Admin\FinanceService;
use App\Domain\Payments\CoinTransaction;
use App\Filament\Support\AdminActions as A;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;

class TransactionResource extends OperationalResource
{
    protected static ?string $model = CoinTransaction::class;

    protected static string $area = 'finance';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('id'), T::make('user_id')->searchable(), T::make('type'), T::make('amount'), T::make('balance_before'), T::make('balance_after'), T::make('code'), T::make('reverses_transaction_id'), T::make('created_at')->dateTime('Y/m/d H:i')])->filters([])->recordActions([A::make('Refund', 'finance', fn ($a, $r, $d) => app(FinanceService::class)->refund($a, $r, $d['reason']))])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTransaction::route('/')];
    }
}
