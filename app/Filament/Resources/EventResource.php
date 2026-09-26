<?php

namespace App\Filament\Resources;

use App\Domain\Admin\ModerationService;
use App\Domain\Events\Event;
use App\Domain\Users\UserStatus;
use App\Filament\Support\AdminActions as A;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventResource extends OperationalResource
{
    protected static ?string $model = Event::class;

    protected static string $area = 'moderation';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('title')->searchable(), T::make('creator_user_id'), T::make('status'), T::make('participants_count'), T::make('city.name'), T::make('starts_at')->dateTime('Y/m/d H:i'), T::make('created_at')->dateTime('Y/m/d H:i')])->filters([])->recordActions([A::make('Cancel event', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->cancelEvent($a, $r, $d['reason'])), A::make('Suspend creator', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->status($a, $r->creator, UserStatus::Suspended, $d['reason']))])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageEvent::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('city')->withCount('participants');
    }
}
