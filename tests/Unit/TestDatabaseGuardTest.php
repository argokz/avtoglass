<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    #[DataProvider('unsafeConnections')]
    public function test_refuses_unsafe_environment_before_any_database_write(string $environment, array $connection): void
    {
        $this->expectException(RuntimeException::class);

        TestDatabaseGuard::assertConfiguration($environment, $connection);
    }

    public static function unsafeConnections(): array
    {
        return [
            'production environment' => ['production', ['driver' => 'pgsql', 'database' => 'avtoglasskz_test']],
            'development environment' => ['local', ['driver' => 'pgsql', 'database' => 'avtoglasskz_test']],
            'working database' => ['testing', ['driver' => 'pgsql', 'database' => 'avtoglasskz']],
            'unrelated test database' => ['testing', ['driver' => 'pgsql', 'database' => 'another_app_test']],
            'misleading suffix' => ['testing', ['driver' => 'pgsql', 'database' => 'avtoglasskz_test_backup']],
            'missing database' => ['testing', ['driver' => 'pgsql']],
            'different engine' => ['testing', ['driver' => 'sqlite', 'database' => ':memory:']],
        ];
    }

    #[DataProvider('safeDatabases')]
    public function test_accepts_dedicated_postgres_database(string $database): void
    {
        TestDatabaseGuard::assertConfiguration('testing', ['driver' => 'pgsql', 'database' => $database]);

        $this->addToAssertionCount(1);
    }

    public static function safeDatabases(): array
    {
        return [['avtoglasskz_test'], ['avtoglasskz_test_test_1'], ['avtoglasskz_test_test_24']];
    }
}
