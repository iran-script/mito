<?php

namespace App\Filament\Resources;

use App\Domain\Admin\FinanceService;
use App\Domain\Memberships\Membership;
use App\Domain\Users\User;
use App\Filament\Support\AdminActions as A;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;

class GoldMembershipResource extends OperationalResource
{
    protected static ?string $model = Membership::class;

    protected static string $area = 'finance';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('user_id')->searchable(), T::make('plan_code'), T::make('status'), T::make('source'), T::make('starts_at')->dateTime('Y/m/d H:i'), T::make('ends_at')->dateTime('Y/m/d H:i')])->filters([])->recordActions([A::make('Revoke', 'finance', fn ($a, $r, $d) => app(FinanceService::class)->revokeGold($a, $r, $d['reason']))])->headerActions([A::make('Grant Gold', 'finance', fn ($a, $r, $d) => app(FinanceService::class)->grantGold($a, User::findOrFail($d['user_id']), isset($d['days']) ? (int) $d['days'] : null, $d['reason']), [TextInput::make('user_id')->label(__('User reference'))->integer()->required()->exists('users', 'id'), TextInput::make('days')->integer()->minValue(1)->maxValue(3650)->helperText(__('Leave empty to use the configured duration.'))])])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageGoldMembership::route('/')];
    }
}
