<?php

namespace App\Filament\Resources;

use App\Domain\Admin\FinanceService;
use App\Domain\Payments\CoinPackage;
use App\Filament\Support\AdminActions as A;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;

class CoinPackageResource extends OperationalResource
{
    protected static ?string $model = CoinPackage::class;

    protected static string $area = 'finance';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('name')->searchable(), T::make('base_coins'), T::make('bonus_coins'), T::make('price_amount'), T::make('currency'), T::make('is_active'), T::make('is_featured'), T::make('sort_order'), T::make('starts_at')->dateTime('Y/m/d H:i'), T::make('ends_at')->dateTime('Y/m/d H:i')])->filters([])->recordActions([A::make('Save package', 'finance', fn ($a, $r, $d) => app(FinanceService::class)->package($a, $r, $d, $d['reason']), [TextInput::make('name')->required(), TextInput::make('base_coins')->numeric()->minValue(1)->required(), TextInput::make('bonus_coins')->numeric()->default(0)->required(), TextInput::make('price_amount')->numeric()->minValue(1)->required(), TextInput::make('currency')->default('IRR')->required(), Toggle::make('is_active')->default(true), Toggle::make('is_featured'), TextInput::make('sort_order')->numeric()->default(0)->required(), DateTimePicker::make('starts_at'), DateTimePicker::make('ends_at')])->fillForm(fn ($record) => $record->toArray())])->headerActions([A::make('Save package', 'finance', fn ($a, $r, $d) => app(FinanceService::class)->package($a, $r, $d, $d['reason']), [TextInput::make('name')->required(), TextInput::make('base_coins')->numeric()->minValue(1)->required(), TextInput::make('bonus_coins')->numeric()->default(0)->required(), TextInput::make('price_amount')->numeric()->minValue(1)->required(), TextInput::make('currency')->default('IRR')->required(), Toggle::make('is_active')->default(true), Toggle::make('is_featured'), TextInput::make('sort_order')->numeric()->default(0)->required(), DateTimePicker::make('starts_at'), DateTimePicker::make('ends_at')])])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCoinPackage::route('/')];
    }
}
