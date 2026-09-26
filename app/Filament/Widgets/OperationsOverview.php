<?php

namespace App\Filament\Widgets;

use App\Domain\Admin\OperationsMetrics;
use App\Support\Presentation;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsOverview extends StatsOverviewWidget
{
    protected function getDescription(): ?string
    {
        return __('Operational snapshot cached for 30 seconds; not an accounting reconciliation.');
    }

    protected function getStats(): array
    {
        return collect(app(OperationsMetrics::class)->snapshot(Filament::auth()->user()))->map(fn ($value, $label) => Stat::make(Presentation::label($label), number_format($value)))->values()->all();
    }
}
