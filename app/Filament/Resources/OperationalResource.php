<?php

namespace App\Filament\Resources;

use App\Domain\Admin\AdminAuthorization;
use App\Domain\Admin\AdminUser;
use App\Support\Presentation;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class OperationalResource extends Resource
{
    protected static string $area = 'moderation';

    public static function getModelLabel(): string
    {
        return Presentation::label(str_replace('Resource', '', class_basename(static::class)));
    }

    public static function getPluralModelLabel(): string
    {
        return static::getModelLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return Presentation::label(static::$area);
    }

    public static function actor(): AdminUser
    {
        $a = Filament::auth()->user();
        abort_unless($a instanceof AdminUser, 403);

        return $a;
    }

    public static function allowed(): bool
    {
        $a = Filament::auth()->user();

        return $a instanceof AdminUser && app(AdminAuthorization::class)->allows($a, static::$area);
    }

    public static function getAuthorizationResponse(string|\UnitEnum $action, ?Model $record = null): Response
    {
        return in_array($action, ['viewAny', 'view'], true) && static::allowed() ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        app(AdminAuthorization::class)->authorize(static::actor(), static::$area);

        return parent::getEloquentQuery();
    }
}
