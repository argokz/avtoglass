<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Имя')
                    ->required(),

                TextInput::make('email')
                    ->label('Почта')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),

                TextInput::make('phone')
                    ->label('Телефон')
                    ->tel(),

                Select::make('role')
                    ->label('Роль')
                    ->options(UserRole::options())
                    ->required()
                    ->default(UserRole::Manager->value)
                    ->helperText('Менеджер — заявки и клиенты. Курьер и мастер — только наряды. Администратор — ещё и справочники.'),

                /*
                 * Пароль на редактировании пустой и необязательный: хеш обратно
                 * не читается, и показывать точки в поле — значит предлагать
                 * сохранить их как новый пароль.
                 */
                TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit'
                        ? 'Оставьте пустым, чтобы не менять'
                        : null),

                Toggle::make('is_active')
                    ->label('Работает')
                    ->default(true)
                    ->helperText('Уволенного снимают этой галочкой, а не удалением: на нём висят наряды и принятые деньги'),
            ]);
    }
}
