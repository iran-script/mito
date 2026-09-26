<?php

namespace App\Filament\Pages;

use App\Domain\Admin\AdminAuthorization;
use App\Domain\Admin\AdminUser;
use App\Domain\Admin\FinanceService;
use App\Filament\Support\AdminActions;
use App\Support\Presentation;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class GoldSettings extends Page
{
    protected string $view = 'filament.pages.admin-settings';

    public static function getNavigationLabel(): string
    {
        return Presentation::label(class_basename(static::class));
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function canAccess(): bool
    {
        $a = Filament::auth()->user();

        return $a instanceof AdminUser && app(AdminAuthorization::class)->allows($a, 'finance');
    }

    public function settings(): array
    {
        abort_unless(static::canAccess(), 403);

        return DB::table('economy_settings')->whereIn('key', ['gold_duration_days', 'gold_bulk_max_recipients_per_action', 'gold_bulk_chat_requests_per_day', 'gold_bulk_direct_messages_per_day', 'gold_bulk_actions_cooldown_seconds', 'gold_priority_enabled'])->pluck('value', 'key')->all();
    }

    protected function getHeaderActions(): array
    {
        return [AdminActions::make('Change setting', 'finance', fn ($a, $r, $d) => app(FinanceService::class)->setting($a, $d['key'], (int) $d['value'], $d['reason']), [Select::make('key')->options(Presentation::options(array_combine(['gold_duration_days', 'gold_bulk_max_recipients_per_action', 'gold_bulk_chat_requests_per_day', 'gold_bulk_direct_messages_per_day', 'gold_bulk_actions_cooldown_seconds', 'gold_priority_enabled'], ['gold_duration_days', 'gold_bulk_max_recipients_per_action', 'gold_bulk_chat_requests_per_day', 'gold_bulk_direct_messages_per_day', 'gold_bulk_actions_cooldown_seconds', 'gold_priority_enabled'])))->required(), TextInput::make('value')->integer()->minValue(0)->required()])];
    }
}
