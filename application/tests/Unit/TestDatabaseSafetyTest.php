<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase as ApplicationTestCase;

class TestDatabaseSafetyTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_phpunit_forces_an_isolated_config_cache_and_test_environment(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $configuration = simplexml_load_file($app->basePath('phpunit.xml'));

        $this->assertSame($app->basePath('bootstrap/cache/phpunit-config.php'), $app->getCachedConfigPath());
        $this->assertSame('testing', getenv('APP_ENV'));
        $this->assertSame('pgsql', getenv('DB_CONNECTION'));
        $this->assertSame('agroconecta_testing', getenv('DB_DATABASE'));
        $this->assertSame('', getenv('DB_URL'));

        foreach (['APP_ENV', 'APP_CONFIG_CACHE', 'DB_DATABASE', 'DB_CONNECTION', 'DB_URL'] as $name) {
            $setting = $configuration->xpath("/phpunit/php/env[@name='{$name}']");
            $this->assertCount(1, $setting);
            $this->assertSame('true', (string) $setting[0]['force']);
            $this->assertSame((string) $setting[0]['value'], Env::get($name));
        }
    }

    public function test_valid_testing_connection_metadata_is_allowed_without_connecting(): void
    {
        $this->invokeGuard($this->application());

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_configurations_fail_before_resolving_a_connection(array $overrides, string $environment = 'testing'): void
    {
        $app = $this->application($overrides, $environment, expectsConnection: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe test database configuration.');

        $this->invokeGuard($app);
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'live application environment' => [[], 'local'],
            'cached production environment' => [['app.env' => 'production']],
            'cached live database' => [['database.connections.pgsql.database' => 'agroconecta']],
            'different default connection' => [['database.default' => 'mysql']],
            'different connection driver' => [['database.connections.pgsql.driver' => 'mysql']],
            'URL overriding the test database' => [['database.connections.pgsql.url' => 'postgresql://localhost/agroconecta']],
            'read database override' => [['database.connections.pgsql.read' => ['database' => 'agroconecta']]],
            'write database override' => [['database.connections.pgsql.write' => ['database' => 'agroconecta']]],
        ];
    }

    public function test_resolved_live_connection_is_rejected_even_when_config_claims_testing(): void
    {
        $app = $this->application(resolvedDatabase: 'agroconecta');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe resolved test connection.');

        $this->invokeGuard($app);
    }

    public function test_cached_live_database_is_rejected_before_refresh_database_can_start(): void
    {
        $app = $this->application(['database.connections.pgsql.database' => 'agroconecta'], expectsConnection: false);
        $app->instance('config_loaded_from_cache', true);
        $harness = new class('unused') extends ApplicationTestCase
        {
            use RefreshDatabase;

            public bool $migrationStarted = false;

            protected array $connectionsToTransact = ['pgsql'];

            public function refreshDatabase(): void
            {
                $this->migrationStarted = true;
            }

            public function setUpDatabaseTraits(Application $app): void
            {
                $this->app = $app;
                $this->setUpTraits();
            }
        };

        $this->expectException(RuntimeException::class);

        try {
            $harness->setUpDatabaseTraits($app);
        } finally {
            $this->assertFalse($harness->migrationStarted);
        }
    }

    private function invokeGuard(Application $app): void
    {
        $harness = new class('unused') extends ApplicationTestCase {};

        (new ReflectionMethod(ApplicationTestCase::class, 'ensureSafeTestingDatabase'))->invoke($harness, $app);
    }

    private function application(array $overrides = [], string $environment = 'testing', bool $expectsConnection = true, string $resolvedDatabase = 'agroconecta_testing'): Application
    {
        $app = new Application(dirname(__DIR__, 2));
        $config = new Repository([
            'app' => ['env' => 'testing'],
            'database' => [
                'default' => 'pgsql',
                'connections' => ['pgsql' => ['driver' => 'pgsql', 'database' => 'agroconecta_testing', 'url' => null]],
            ],
        ]);
        $config->set($overrides);
        $app->instance('env', $environment);
        $app->instance('config', $config);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('pgsql');
        $connection->method('getDatabaseName')->willReturn($resolvedDatabase);
        foreach (['getPdo', 'getReadPdo', 'select', 'statement', 'beginTransaction'] as $method) {
            $connection->expects($this->never())->method($method);
        }

        $database = $this->createMock(DatabaseManager::class);
        $database->expects($expectsConnection ? $this->once() : $this->never())
            ->method('connection')->willReturn($connection);
        $app->instance('db', $database);

        return $app;
    }
}
