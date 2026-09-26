<?php

namespace App\Filament\Resources;

use App\Domain\Admin\AdminUser;
use App\Domain\Admin\ModerationService;
use App\Domain\Admin\ReportInspection;
use App\Domain\Moderation\Report;
use App\Domain\Moderation\ReportStatus;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use App\Filament\Support\AdminActions as A;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReportResource extends OperationalResource
{
    protected static ?string $model = Report::class;

    protected static string $area = 'moderation';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('id'), T::make('reporter_user_id'), T::make('reported_user_id'), T::make('reason'), T::make('description')->wrap()->limit(500), T::make('status')->badge(), T::make('assigned_admin_id'), T::make('internal_notes')->wrap()->limit(500), T::make('created_at')->dateTime('Y/m/d H:i'), T::make('referenced_content')->state(fn ($record) => app(ReportInspection::class)->content(static::actor(), $record))->wrap()->limit(1000)])->filters([SelectFilter::make('status')->options(['open' => __('Open'), 'reviewing' => __('Reviewing'), 'resolved' => __('Resolved'), 'dismissed' => __('Dismissed')])])->recordActions([A::make('Review', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->report($a, $r->id, ReportStatus::from($d['status']), $d['reason'], $d['internal_notes'] ?? null, $d['assigned_admin_id'] ?? null, false), [Select::make('status')->options(['open' => __('Open'), 'reviewing' => __('Reviewing'), 'resolved' => __('Resolved'), 'dismissed' => __('Dismissed')])->required(), Textarea::make('internal_notes')->maxLength(5000), Select::make('assigned_admin_id')->options(fn () => AdminUser::where('is_active', true)->whereIn('role', ['super_admin', 'moderator'])->pluck('name', 'id'))])->fillForm(fn ($record) => ['status' => $record->getRawOriginal('status'), 'internal_notes' => $record->internal_notes, 'assigned_admin_id' => $record->assigned_admin_id]), A::make('Suspend reported user', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->status($a, User::findOrFail($r->reported_user_id), UserStatus::Suspended, $d['reason'])), A::make('Ban reported user', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->status($a, User::findOrFail($r->reported_user_id), UserStatus::Banned, $d['reason']))])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageReport::route('/')];
    }
}
