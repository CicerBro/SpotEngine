<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->guardAgainstProductionDatabase();

        parent::setUp();
    }

    private function guardAgainstProductionDatabase(): void
    {
        // Check the raw env var set by phpunit.xml — allowlist only.
        $envDatabase = (string) (getenv('DB_DATABASE') ?: '');
        if ($envDatabase !== 'spotengine_test') {
            $this->abortWrongDatabase("DB_DATABASE env is \"{$envDatabase}\" instead of \"spotengine_test\"");
        }

        // Also check the cached config file directly, bypassing Laravel entirely.
        // A stale config cache will override phpunit.xml env vars and point at the prod DB.
        $cachedConfig = __DIR__.'/../bootstrap/cache/config.php';
        if (file_exists($cachedConfig)) {
            $config = require $cachedConfig;
            $default = $config['database']['default'] ?? 'pgsql';
            $cachedDatabase = $config['database']['connections'][$default]['database'] ?? '';
            if ($cachedDatabase !== 'spotengine_test') {
                $this->abortWrongDatabase("cached config points at \"{$cachedDatabase}\" database instead of \"spotengine_test\" database.
                \n  Run: \"php artisan config:clear\" or use \"composer test\" instead of \"php artisan test\"");
            }
        }
    }

    private function abortWrongDatabase(string $reason): never
    {
        throw new \RuntimeException(
            "Tests would use your application database and wipe data! \n\n  Reason: ({$reason}). "
            .'Use a separate test DB: "DB_DATABASE=spotengine_test" in phpunit.xml.'
        );
    }
}
