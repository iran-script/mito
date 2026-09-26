<?php

namespace App\Filament\Resources;

use App\Domain\Admin\ModerationService;
use App\Domain\Moderation\RestrictionService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use App\Filament\Support\AdminActions as A;
use App\Support\Presentation;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UserResource extends OperationalResource
{
    protected static ?string $model = User::class;

    protected static string $area = 'moderation';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('id')->label(__('User reference'))->sortable(), T::make('profile.display_name')->searchable(), T::make('profile.gender'), T::make('profile.birth_date')->label(__('Age'))->formatStateUsing(fn ($state) => Carbon::parse($state)->age), T::make('profile.city.name')->searchable(), T::make('status')->badge(), T::make('profile.profile_completed_at')->dateTime('Y/m/d H:i'), T::make('gold_active')->label(__('Gold'))->formatStateUsing(fn ($state) => $state ? __('Yes') : __('No')), T::make('wallet.balance'), T::make('report_count'), T::make('block_count'), T::make('last_activity_at')->dateTime('Y/m/d H:i')->sortable(), T::make('created_at')->dateTime('Y/m/d H:i')])->filters([SelectFilter::make('status')->options(['active' => __('Active'), 'suspended' => __('Suspended'), 'banned' => __('Banned')]), SelectFilter::make('city')->relationship('profile.city', 'name')->getOptionLabelFromRecordUsing(fn ($record) => Presentation::label($record->name)), Filter::make('reported')->query(fn ($q) => $q->whereExists(fn ($s) => $s->from('reports')->whereColumn('reported_user_id', 'users.id'))), Filter::make('recent')->query(fn ($q) => $q->where('last_activity_at', '>=', now()->subDays(7))), Filter::make('Gold')->query(fn ($q) => $q->whereExists(fn ($s) => $s->from('memberships')->whereColumn('user_id', 'users.id')->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())))])->recordActions([
            A::make('Suspend', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->status($a, $r, UserStatus::Suspended, $d['reason'])),
            A::make('Ban', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->status($a, $r, UserStatus::Banned, $d['reason'])),
            A::make('Restore', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->status($a, $r, UserStatus::Active, $d['reason'])),
            A::make('Restrict', 'moderation', fn ($a, $r, $d) => app(ModerationService::class)->restrict($a, $r, $d['type'], $d['reason'], empty($d['ends_at']) ? null : CarbonImmutable::parse($d['ends_at'])), [Select::make('type')->options(Presentation::options(array_combine(RestrictionService::TYPES, RestrictionService::TYPES)))->required(), DateTimePicker::make('ends_at')]),
        ])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUser::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['profile.city', 'wallet'])->select('users.*')->selectSub(DB::table('reports')->selectRaw('count(*)')->whereColumn('reported_user_id', 'users.id'), 'report_count')->selectSub(DB::table('user_blocks')->selectRaw('count(*)')->whereColumn('blocked_user_id', 'users.id'), 'block_count')->selectSub(DB::table('memberships')->selectRaw('count(*)')->whereColumn('user_id', 'users.id')->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now()), 'gold_active');
    }
}
