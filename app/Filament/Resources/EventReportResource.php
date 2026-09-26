<?php

namespace App\Filament\Resources;

use App\Domain\Admin\AdminUser;
use App\Domain\Admin\EventReport;
use App\Domain\Admin\ModerationService;
use App\Domain\Moderation\ReportStatus;
use App\Filament\Support\AdminActions as A;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EventReportResource extends OperationalResource
{
    protected static ?string $model = EventReport::class;

    protected static string $area = 'moderation';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('id'), T::make('reporter_user_id'), T::make('event_id'), T::make('reason'), T::make('description')->wrap()->limit(500), T::make('status')->badge(), T::make('assigned_admin_id'), T::make('internal_notes')->wrap()->limit(500), T::make('created_at')->dateTime('Y/m/d H:i')])->filters([SelectFilter::make('status')->options(['open' => __('Open'), 'reviewing' => __('Reviewing'), 'resolved' => __('Resolved'), 'dismissed' => __('Dismissed')])])->recordActions([A::make('Review', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->report($a, $r->id, ReportStatus::from($d['status']), $d['reason'], $d['internal_notes'] ?? null, $d['assigned_admin_id'] ?? null, true), [Select::make('status')->options(['open' => __('Open'), 'reviewing' => __('Reviewing'), 'resolved' => __('Resolved'), 'dismissed' => __('Dismissed')])->required(), Textarea::make('internal_notes')->maxLength(5000), Select::make('assigned_admin_id')->options(fn () => AdminUser::where('is_active', true)->whereIn('role', ['super_admin', 'moderator'])->pluck('name', 'id'))])->fillForm(fn ($record) => ['status' => $record->getRawOriginal('status'), 'internal_notes' => $record->internal_notes, 'assigned_admin_id' => $record->assigned_admin_id])])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageEventReport::route('/')];
    }
}
