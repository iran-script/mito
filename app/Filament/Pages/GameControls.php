<?php

namespace App\Filament\Pages;

use App\Domain\Admin\AdminAuthorization;
use App\Domain\Admin\AdminUser;
use App\Domain\Admin\GameOperationsService;
use App\Domain\Games\GameType;
use App\Filament\Support\AdminActions;
use App\Support\Presentation;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class GameControls extends Page
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

        return $a instanceof AdminUser && app(AdminAuthorization::class)->allows($a, 'operations');
    }

    public static function types(): array
    {
        return [...array_column(GameType::cases(), 'value'), 'daily_challenge'];
    }

    public function settings(): array
    {
        abort_unless(static::canAccess(), 403);
        $result = [];
        foreach (static::types() as $t) {
            $result[$t] = (DB::table('economy_settings')->where('key', 'game_enabled_'.$t)->value('value') ?? '1') === '1' ? 'Enabled' : 'Disabled for new starts';
        }

        return $result;
    }

    protected function getHeaderActions(): array
    {
        return [AdminActions::make('Set availability', 'operations', fn ($a, $r, $d) => app(GameOperationsService::class)->toggle($a, $d['type'], (bool) $d['enabled'], $d['reason']), [Select::make('type')->options(Presentation::options(array_combine(static::types(), static::types())))->required(), Toggle::make('enabled')->default(true)])];
    }
}
