<?php

namespace App\Providers;

use App\Support\Presentation;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Support\ServiceProvider;

class LocalizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Field::configureUsing(function (Field $field) {
            $field->label(fn () => Presentation::label($field->getName()));
            if ($field instanceof DateTimePicker) {
                $field->timezone(config('presentation.timezone'))->displayFormat('Y/m/d H:i');
            }
        });
        BaseFilter::configureUsing(fn (BaseFilter $filter) => $filter->label(fn () => Presentation::label($filter->getName())));
        TextColumn::configureUsing(function (TextColumn $column) {
            $column->label(fn () => Presentation::label($column->getName()))
                ->timezone(config('presentation.timezone'))
                ->formatStateUsing(function ($state) use ($column) {
                    if (is_bool($state)) {
                        return $state ? __('Yes') : __('No');
                    }
                    if (in_array(class_basename($column->getRecord()), ['Badge', 'QuizQuestion', 'CoinPackage'], true) && is_string($state)) {
                        return Presentation::label($state);
                    }
                    if ($state instanceof BackedEnum || in_array($column->getName(), ['type', 'status', 'game_type', 'profile.gender', 'profile.city.name', 'city.name', 'feature_code', 'restriction_type', 'plan_code', 'code', 'currency', 'currency_snapshot', 'reason', 'action', 'subject_type', 'source'], true)) {
                        return Presentation::label($state);
                    }

                    return $state;
                });
        });
    }
}
