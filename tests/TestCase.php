<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // This runs before RefreshDatabase is allowed to migrate or truncate.
        TestDatabaseGuard::assertSafe($app);
        Http::preventStrayRequests();

        return $app;
    }

    /** Данные формы заявки, включая согласие на персональные данные. */
    protected function orderPayload(array $override = []): array
    {
        return array_merge([
            'name' => 'Данил',
            'phone' => '+7 700 123-45-67',
            'privacy_consent' => '1',
        ], $override);
    }
}
