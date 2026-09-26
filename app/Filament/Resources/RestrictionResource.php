<?php

namespace App\Filament\Resources;

use App\Domain\Admin\ModerationService;
use App\Domain\Admin\UserRestriction;
use App\Filament\Support\AdminActions as A;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;

class RestrictionResource extends OperationalResource
{
    protected static ?string $model = UserRestriction::class;

    protected static string $area = 'moderation';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('user_id')->searchable(), T::make('restriction_type'), T::make('starts_at')->dateTime('Y/m/d H:i'), T::make('ends_at')->dateTime('Y/m/d H:i'), T::make('reason')->wrap()])->filters([])->recordActions([A::make('Lift', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->lift($a, $r->id, $d['reason']))])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageRestriction::route('/')];
    }
}
