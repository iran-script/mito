<?php

namespace App\Filament\Resources;

use App\Domain\Admin\FinanceService;
use App\Domain\Payments\Wallet;
use App\Domain\Users\User;
use App\Filament\Support\AdminActions as A;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class WalletResource extends OperationalResource
{
    protected static ?string $model = Wallet::class;

    protected static string $area = 'finance';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('user_id')->searchable(), T::make('balance')->sortable(), T::make('updated_at')->dateTime('Y/m/d H:i')])->filters([])->recordActions([A::make('Credit coins', 'finance', fn ($a, $r, $d) => app(FinanceService::class)->wallet($a, User::findOrFail($r->user_id), (int) $d['amount'], true, $d['reason'], $d['operation_key']), [TextInput::make('amount')->integer()->minValue(1)->required(), TextInput::make('operation_key')->default(fn () => (string) Str::uuid())->required()]), A::make('Debit coins', 'finance', fn ($a, $r, $d) => app(FinanceService::class)->wallet($a, User::findOrFail($r->user_id), (int) $d['amount'], false, $d['reason'], $d['operation_key']), [TextInput::make('amount')->integer()->minValue(1)->required(), TextInput::make('operation_key')->default(fn () => (string) Str::uuid())->required()])])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageWallet::route('/')];
    }
}
