<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Concerns\OnlySalesStaff;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Models\Task;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TaskResource extends Resource
{
    use OnlySalesStaff;

    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'Заказы';

    protected static ?string $navigationLabel = 'Задачи';

    protected static ?string $modelLabel = 'задача';

    protected static ?string $pluralModelLabel = 'Задачи';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * В меню висит число просроченных, а не всех открытых: список задач
     * открывают, чтобы понять, что уже горит.
     */
    public static function getNavigationBadge(): ?string
    {
        $overdue = static::getModel()::overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TasksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }
}
