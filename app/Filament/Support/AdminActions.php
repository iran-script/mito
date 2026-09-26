<?php

namespace App\Filament\Support;

use App\Domain\Admin\AdminAuthorization;
use App\Domain\Admin\AdminUser;
use App\Support\Presentation;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AdminActions
{
    public static function make(string $name, string $area, callable $handler, array $fields = []): Action
    {
        return Action::make($name)->label(Presentation::label($name))->modalHeading(Presentation::label($name))->visible(fn () => Filament::auth()->user() instanceof AdminUser && app(AdminAuthorization::class)->allows(Filament::auth()->user(), $area))
            ->schema([...$fields, Textarea::make('reason')->required()->maxLength(2000)])->action(function (array $data, ?Model $record = null) use ($handler, $area) {
                $a = Filament::auth()->user();
                abort_unless($a instanceof AdminUser, 403);
                app(AdminAuthorization::class)->authorize($a, $area);
                try {
                    $handler($a, $record, $data);
                    Notification::make()->title(__('Saved'))->success()->send();
                } catch (\DomainException $e) {
                    throw ValidationException::withMessages(['reason' => Presentation::error($e->getMessage())]);
                }
            });
    }
}
