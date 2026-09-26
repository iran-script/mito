<?php

namespace App\Filament\Resources;

use App\Domain\Admin\GameOperationsService;
use App\Domain\Admin\QuizQuestion;
use App\Filament\Support\AdminActions as A;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ThisOrThatResource extends OperationalResource
{
    protected static ?string $model = QuizQuestion::class;

    protected static string $area = 'operations';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('question')->searchable()->wrap(), T::make('options'), T::make('is_active'), T::make('updated_at')->dateTime('Y/m/d H:i')])->filters([])->recordActions([A::make('Save question', 'operations', fn ($a, $r, $d) => app(GameOperationsService::class)->question($a, $r?->id, $d, true, $d['reason']), [Textarea::make('question')->required()->maxLength(1000), TagsInput::make('options')->required(), Toggle::make('is_active')->default(true)])->fillForm(fn ($record) => $record->toArray())])->headerActions([A::make('Save question', 'operations', fn ($a, $r, $d) => app(GameOperationsService::class)->question($a, $r?->id, $d, true, $d['reason']), [Textarea::make('question')->required()->maxLength(1000), TagsInput::make('options')->required(), Toggle::make('is_active')->default(true)])])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageThisOrThat::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('category', 'this_or_that');
    }
}
