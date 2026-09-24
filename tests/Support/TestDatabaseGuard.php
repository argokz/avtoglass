<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

/** Refuse to run RefreshDatabase against the development or production database. */
final class TestDatabaseGuard
{
    public static function assertSafe(Application $app): void
    {
        $connection = $app->make('db')->connection();

        self::assertConfiguration($app->environment(), $connection->getConfig());

        $actual = $connection->selectOne('SELECT current_database() AS name')->name;

        if ($actual !== $connection->getDatabaseName()) {
            throw new RuntimeException('Tests stopped: PostgreSQL connected to an unexpected database.');
        }
    }

    public static function assertConfiguration(string $environment, array $connection): void
    {
        if ($environment !== 'testing') {
            throw new RuntimeException('Tests stopped: APP_ENV must be testing.');
        }

        if (($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Tests stopped: the suite requires PostgreSQL.');
        }

        // Laravel parallel testing appends _test_<token> to the base database.
        if (! preg_match('/\Aavtoglasskz_test(?:_test_[0-9]+)?\z/', (string) ($connection['database'] ?? ''))) {
            throw new RuntimeException('Tests stopped: use avtoglasskz_test or its parallel-test database.');
        }
    }
}
