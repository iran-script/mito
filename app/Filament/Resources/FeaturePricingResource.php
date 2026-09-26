<?php

namespace App\Filament\Resources;

use App\Domain\Admin\FeaturePrice;
use App\Domain\Admin\FinanceService;
use App\Domain\Payments\PaidFeature;
use App\Filament\Support\AdminActions as A;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;

class FeaturePricingResource extends OperationalResource
{
    protected static ?string $model = FeaturePrice::class;

    protected static string $area = 'finance';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('feature_code'), T::make('coin_cost'), T::make('is_active')])->filters([])->recordActions([A::make('Change price', 'finance', fn ($a, $r, $d) => app(FinanceService::class)->price($a, PaidFeature::from($r->feature_code), (int) $d['coin_cost'], (bool) $d['is_active'], $d['reason']), [TextInput::make('coin_cost')->integer()->minValue(0)->required(), Toggle::make('is_active')])->fillForm(fn ($record) => ['coin_cost' => $record->coin_cost, 'is_active' => $record->is_active])])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageFeaturePricing::route('/')];
    }
}
