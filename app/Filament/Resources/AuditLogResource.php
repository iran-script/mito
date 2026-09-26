<?php

namespace App\Filament\Resources;

use App\Domain\Admin\AdminAuditLog;
use Filament\Tables\Columns\TextColumn as T;
use Filament\Tables\Table;

class AuditLogResource extends OperationalResource
{
    protected static ?string $model = AdminAuditLog::class;

    protected static string $area = 'audit';

    public static function table(Table $table): Table
    {
        return $table->columns([T::make('admin_user_id'), T::make('action')->searchable(), T::make('subject_type'), T::make('subject_id'), T::make('reason')->wrap(), T::make('created_at')->dateTime('Y/m/d H:i')])->filters([])->recordActions([])->headerActions([])->defaultSort('id', 'desc')->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAuditLog::route('/')];
    }
}
