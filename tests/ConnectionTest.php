<?php

namespace Greatcode\ControllerGlobal\Tests;

use Greatcode\ControllerGlobal\Connection;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

class ConnectionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function sqlite(array $options = []): Connection
    {
        return new Connection('sqlite::memory:', '', '', $options);
    }

    private function resetSingleton(): void
    {
        $ref = new ReflectionProperty(Connection::class, 'instance');
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
    }

    // -------------------------------------------------------------------------
    // Constructor — default PDO attributes
    // -------------------------------------------------------------------------

    public function test_constructor_sets_errmode_exception(): void
    {
        $conn = $this->sqlite();
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $conn->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function test_constructor_sets_fetch_assoc(): void
    {
        $conn = $this->sqlite();
        $this->assertSame(PDO::FETCH_ASSOC, $conn->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    public function test_constructor_disables_emulate_prepares(): void
    {
        $conn = $this->sqlite();
        $this->assertFalse((bool) $conn->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function test_constructor_options_override_defaults(): void
    {
        $conn = $this->sqlite([PDO::ATTR_EMULATE_PREPARES => true]);
        $this->assertTrue((bool) $conn->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function test_constructor_is_pdo_instance(): void
    {
        $this->assertInstanceOf(PDO::class, $this->sqlite());
    }

    // -------------------------------------------------------------------------
    // fromConfig()
    // -------------------------------------------------------------------------

    public function test_fromConfig_sqlite_returns_connection(): void
    {
        $conn = Connection::fromConfig(['driver' => 'sqlite', 'dbname' => ':memory:']);
        $this->assertInstanceOf(Connection::class, $conn);
    }

    public function test_fromConfig_sqlite_uses_default_options(): void
    {
        $conn = Connection::fromConfig(['driver' => 'sqlite', 'dbname' => ':memory:']);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $conn->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function test_fromConfig_missing_driver_defaults_to_mysql(): void
    {
        // No MySQL server available → PDOException, NOT InvalidArgumentException.
        $this->expectException(PDOException::class);
        Connection::fromConfig(['host' => '127.0.0.1', 'dbname' => 'test']);
    }

    public function test_fromConfig_throws_on_unsupported_driver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver: mongodb');
        Connection::fromConfig(['driver' => 'mongodb']);
    }

    public function test_fromConfig_pgsql_builds_dsn_and_fails_without_server(): void
    {
        $this->expectException(PDOException::class);
        Connection::fromConfig([
            'driver'  => 'pgsql',
            'host'    => '127.0.0.1',
            'port'    => 5432,
            'dbname'  => 'nonexistent',
        ]);
    }

    public function test_fromConfig_mysql_builds_dsn_and_fails_without_server(): void
    {
        $this->expectException(PDOException::class);
        Connection::fromConfig([
            'driver'  => 'mysql',
            'host'    => '127.0.0.1',
            'port'    => 3306,
            'dbname'  => 'nonexistent',
        ]);
    }

    public function test_fromConfig_passes_options_to_constructor(): void
    {
        $conn = Connection::fromConfig([
            'driver'  => 'sqlite',
            'dbname'  => ':memory:',
            'options' => [PDO::ATTR_EMULATE_PREPARES => true],
        ]);
        $this->assertTrue((bool) $conn->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    // -------------------------------------------------------------------------
    // fromEnv()
    // -------------------------------------------------------------------------

    public function test_fromEnv_reads_sqlite_env_vars(): void
    {
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=:memory:');

        $conn = Connection::fromEnv();
        $this->assertInstanceOf(Connection::class, $conn);

        putenv('DB_DRIVER');
        putenv('DB_NAME');
    }

    public function test_fromEnv_without_driver_defaults_to_mysql_and_fails(): void
    {
        // Unset DB_DRIVER so it defaults to 'mysql'
        putenv('DB_DRIVER');
        putenv('DB_NAME=nonexistent');

        $this->expectException(PDOException::class);
        Connection::fromEnv();

        putenv('DB_NAME');
    }

    public function test_fromEnv_connection_has_correct_attributes(): void
    {
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=:memory:');

        $conn = Connection::fromEnv();
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $conn->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertSame(PDO::FETCH_ASSOC, $conn->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));

        putenv('DB_DRIVER');
        putenv('DB_NAME');
    }

    // -------------------------------------------------------------------------
    // getInstance() — singleton
    // -------------------------------------------------------------------------

    public function test_getInstance_returns_connection(): void
    {
        $conn = Connection::getInstance('sqlite::memory:');
        $this->assertInstanceOf(Connection::class, $conn);
    }

    public function test_getInstance_returns_same_instance(): void
    {
        $a = Connection::getInstance('sqlite::memory:');
        $b = Connection::getInstance('sqlite::memory:');
        $this->assertSame($a, $b);
    }

    public function test_getInstance_after_reset_creates_new_instance(): void
    {
        $a = Connection::getInstance('sqlite::memory:');
        $this->resetSingleton();
        $b = Connection::getInstance('sqlite::memory:');

        $this->assertNotSame($a, $b);
    }

    // -------------------------------------------------------------------------
    // transaction()
    // -------------------------------------------------------------------------

    public function test_transaction_commits_on_success(): void
    {
        $conn = $this->sqlite();
        $conn->exec('CREATE TABLE t (v TEXT)');

        $conn->transaction(function (Connection $db) {
            $db->exec("INSERT INTO t VALUES ('hello')");
        });

        $row = $conn->query('SELECT v FROM t')->fetch();
        $this->assertSame('hello', $row['v']);
    }

    public function test_transaction_returns_callback_value(): void
    {
        $conn   = $this->sqlite();
        $result = $conn->transaction(fn() => 'done');

        $this->assertSame('done', $result);
    }

    public function test_transaction_rolls_back_on_exception(): void
    {
        $conn = $this->sqlite();
        $conn->exec('CREATE TABLE t (v TEXT)');

        try {
            $conn->transaction(function (Connection $db) {
                $db->exec("INSERT INTO t VALUES ('should-not-persist')");
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $count = $conn->query('SELECT COUNT(*) AS c FROM t')->fetch()['c'];
        $this->assertSame('0', (string) $count);
    }

    public function test_transaction_rethrows_exception(): void
    {
        $conn = $this->sqlite();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rethrown');

        $conn->transaction(function () {
            throw new RuntimeException('rethrown');
        });
    }

    public function test_transaction_returns_null_when_callback_returns_null(): void
    {
        $conn   = $this->sqlite();
        $result = $conn->transaction(fn() => null);

        $this->assertNull($result);
    }

    public function test_transaction_passes_connection_to_callback(): void
    {
        $conn     = $this->sqlite();
        $received = null;

        $conn->transaction(function (Connection $db) use (&$received) {
            $received = $db;
        });

        $this->assertSame($conn, $received);
    }
}
