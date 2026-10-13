<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->ensureSafeTestingDatabase($app);

        return $app;
    }

    protected function setUpTraits(): array
    {
        $this->ensureSafeTestingDatabase($this->app);

        return parent::setUpTraits();
    }

    private function ensureSafeTestingDatabase(Application $app): void
    {
        $config = $app->make('config');
        $connectionConfig = $config->get('database.connections.pgsql', []);

        if (! $app->environment('testing')
            || $config->get('app.env') !== 'testing'
            || $config->get('database.default') !== 'pgsql'
            || ($connectionConfig['driver'] ?? null) !== 'pgsql'
            || ($connectionConfig['database'] ?? null) !== 'agroconecta_testing'
            || ! in_array($connectionConfig['url'] ?? null, [null, ''], true)
            || isset($connectionConfig['read'])
            || isset($connectionConfig['write'])) {
            throw new RuntimeException('Unsafe test database configuration. Tests require APP_ENV=testing and PostgreSQL database agroconecta_testing, without a connection URL or read/write overrides.');
        }

        $connection = $app->make('db')->connection();

        if ($connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== 'agroconecta_testing') {
            throw new RuntimeException('Unsafe resolved test connection. Only the PostgreSQL database agroconecta_testing is allowed.');
        }

        if (method_exists($this, 'connectionsToTransact')) {
            foreach ($this->connectionsToTransact() as $name) {
                if (! in_array($name, [null, 'pgsql'], true)) {
                    throw new RuntimeException('Unsafe test transaction connection. Only the pgsql testing connection is allowed.');
                }
            }
        }
    }
}
