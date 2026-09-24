<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/** Explicitly isolated UI fixtures: never load this behavior in local/production. */
class PreviewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('preview')) {
            return;
        }

        // Use resolved connection settings: DB_URL can override the plain config name.
        $connection = $this->app->make('db')->connection();
        if ($connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== 'avtoglasskz_modernization_preview'
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Preview requires its dedicated database.');
        }
        if ($connection->selectOne('SELECT current_database() AS name')->name !== 'avtoglasskz_modernization_preview') {
            throw new RuntimeException('Preview connected to an unexpected database.');
        }

        Http::preventStrayRequests();
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:20480']]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [[
                    'text' => 'Это демонстрационный диалог. Для подбора уточните модель, год и наличие камеры за стеклом. Данные и заказы этого стенда используются только для проверки интерфейса.',
                ]]]]],
            ]),
        ]);
    }
}
