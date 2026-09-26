<?php

namespace App\Filament\Resources;

use App\Domain\Admin\GameOperationsService;
use App\Domain\Games\GameSession;
use App\Filament\Support\AdminActions as A;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GameSessionResource extends OperationalResource
{
    protected static ?string $model = GameSession::class;

    protected static string $area = 'operations';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('id'), T::make('game_type'), T::make('status'), T::make('participants.id')->label(__('Participant references')), T::make('current_round'), T::make('created_at')->dateTime('Y/m/d H:i'), T::make('expires_at')->dateTime('Y/m/d H:i'), T::make('completed_at')->state(fn ($record) => $record->status->value === 'completed' ? $record->updated_at : null)->dateTime('Y/m/d H:i'), T::make('updated_at')->dateTime('Y/m/d H:i')])->filters([SelectFilter::make('status')->options(['waiting' => 'Waiting', 'active' => __('Active'), 'completed' => 'Completed', 'expired' => 'Expired', 'cancelled' => 'Cancelled'])])->recordActions([A::make('Cancel session', 'operations', fn ($a, $r, $d) => app(GameOperationsService::class)->cancel($a, $r, $d['reason']))])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageGameSession::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('participants');
    }
}
