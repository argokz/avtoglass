<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn ($record) => $record->email),

                TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state) => $state->label()),

                TextColumn::make('phone')
                    ->label('Телефон')
                    ->placeholder('не указан')
                    ->url(fn ($record) => $record->phone ? 'tel:'.$record->phone : null),

                IconColumn::make('is_active')
                    ->label('Работает')
                    ->boolean(),

                IconColumn::make('has_app_authentication')
                    ->label('2FA')
                    ->boolean()
                    ->getStateUsing(fn ($record) => filled($record->app_authentication_secret))
                    ->tooltip(fn ($record) => filled($record->app_authentication_secret)
                        ? 'Приложение-аутентификатор включено'
                        : 'Ещё не включал в профиле'),

                TextColumn::make('created_at')
                    ->label('Заведён')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Роль')
                    ->multiple()
                    ->options(UserRole::options()),

                TernaryFilter::make('is_active')
                    ->label('Работает'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->label('Открыть'),
            ]);
    }
}
