<?php

namespace Greatcode\ControllerGlobal\Tests\Integration;

use Greatcode\ControllerGlobal\Connection;
use Greatcode\ControllerGlobal\CtrlGlobal;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration tests against a real PostgreSQL server.
 *
 * Required env vars:
 *   PGSQL_NAME      – database to connect to (skipped when absent)
 *
 * Optional env vars (with defaults):
 *   PGSQL_HOST      – default 127.0.0.1
 *   PGSQL_PORT      – default 5432
 *   PGSQL_USER      – default postgres
 *   PGSQL_PASSWORD  – default (empty)
 *
 * @requires extension pdo_pgsql
 */
class PgsqlIntegrationTest extends TestCase
{
    private static ?Connection $conn = null;
    private CtrlGlobal $ctrl;

    // -------------------------------------------------------------------------
    // Suite bootstrap / teardown
    // -------------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        $name = getenv('PGSQL_NAME') ?: '';

        if ($name === '') {
            self::markTestSkipped(
                'Set PGSQL_NAME (+ PGSQL_HOST, PGSQL_PORT, PGSQL_USER, PGSQL_PASSWORD) to run PostgreSQL integration tests.'
            );
        }

        $host = getenv('PGSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('PGSQL_PORT') ?: 5432);
        $user = getenv('PGSQL_USER') ?: 'postgres';
        $pass = getenv('PGSQL_PASSWORD') ?: '';

        try {
            self::$conn = Connection::fromConfig([
                'driver'   => 'pgsql',
                'host'     => $host,
                'port'     => $port,
                'dbname'   => $name,
                'username' => $user,
                'password' => $pass,
            ]);
        } catch (PDOException $e) {
            self::markTestSkipped("Could not connect to PostgreSQL: {$e->getMessage()}");
        }

        self::$conn->exec('
            CREATE TABLE IF NOT EXISTS integration_users (
                id   SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                role VARCHAR(50)  NOT NULL DEFAULT \'user\',
                age  INTEGER
            )
        ');
    }

    public static function tearDownAfterClass(): void
    {
        self::$conn?->exec('DROP TABLE IF EXISTS integration_users');
    }

    protected function setUp(): void
    {
        // RESTART IDENTITY resets the SERIAL sequence between tests
        self::$conn->exec('TRUNCATE TABLE integration_users RESTART IDENTITY');
        $this->ctrl = new CtrlGlobal(self::$conn);
    }

    // -------------------------------------------------------------------------
    // Connection — attributes
    // -------------------------------------------------------------------------

    public function test_fromConfig_pgsql_returns_connection(): void
    {
        $this->assertInstanceOf(Connection::class, self::$conn);
    }

    public function test_connection_errmode_is_exception(): void
    {
        $this->assertSame(PDO::ERRMODE_EXCEPTION, self::$conn->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function test_connection_default_fetch_is_assoc(): void
    {
        $this->assertSame(PDO::FETCH_ASSOC, self::$conn->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    public function test_connection_emulate_prepares_is_disabled(): void
    {
        // PostgreSQL supports this attribute natively (unlike SQLite)
        $this->assertFalse((bool) self::$conn->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    // -------------------------------------------------------------------------
    // Connection — fromEnv()
    // -------------------------------------------------------------------------

    public function test_fromEnv_connects_with_pgsql_env_vars(): void
    {
        // Temporarily populate DB_* so Connection::fromEnv() sees pgsql credentials
        $host = getenv('PGSQL_HOST') ?: '127.0.0.1';
        $port = getenv('PGSQL_PORT') ?: '5432';
        $name = getenv('PGSQL_NAME') ?: '';
        $user = getenv('PGSQL_USER') ?: 'postgres';
        $pass = getenv('PGSQL_PASSWORD') ?: '';

        putenv("DB_DRIVER=pgsql");
        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$pass}");

        try {
            $conn = Connection::fromEnv();
            $this->assertInstanceOf(Connection::class, $conn);
            $this->assertSame(PDO::ERRMODE_EXCEPTION, $conn->getAttribute(PDO::ATTR_ERRMODE));
        } finally {
            putenv('DB_DRIVER');
            putenv('DB_HOST');
            putenv('DB_PORT');
            putenv('DB_NAME');
            putenv('DB_USER');
            putenv('DB_PASSWORD');
        }
    }

    // -------------------------------------------------------------------------
    // Connection — transaction()
    // -------------------------------------------------------------------------

    public function test_transaction_commits_on_success(): void
    {
        self::$conn->transaction(function (Connection $db): void {
            $db->exec("INSERT INTO integration_users (name, role) VALUES ('TxAlice', 'user')");
        });

        $row = self::$conn->query("SELECT name FROM integration_users WHERE name = 'TxAlice'")->fetch();
        $this->assertSame('TxAlice', $row['name']);
    }

    public function test_transaction_rolls_back_on_exception(): void
    {
        try {
            self::$conn->transaction(function (Connection $db): void {
                $db->exec("INSERT INTO integration_users (name, role) VALUES ('TxBob', 'user')");
                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
        }

        $count = self::$conn
            ->query("SELECT COUNT(*) AS c FROM integration_users WHERE name = 'TxBob'")
            ->fetch()['c'];

        $this->assertSame('0', (string) $count);
    }

    public function test_transaction_returns_callback_value(): void
    {
        $result = self::$conn->transaction(fn() => 'pgsql-ok');
        $this->assertSame('pgsql-ok', $result);
    }

    // -------------------------------------------------------------------------
    // CtrlGlobal — DML
    // -------------------------------------------------------------------------

    public function test_insert_persists_row(): void
    {
        $this->ctrl->insert('integration_users', ['name' => 'Alice', 'role' => 'admin']);

        $row = self::$conn
            ->query("SELECT name, role FROM integration_users WHERE name = 'Alice'")
            ->fetch();

        $this->assertSame('Alice', $row['name']);
        $this->assertSame('admin', $row['role']);
    }

    public function test_insertAll_persists_multiple_rows(): void
    {
        $this->ctrl->insertAll('integration_users', [
            ['name' => 'Bob',   'role' => 'user'],
            ['name' => 'Carol', 'role' => 'mod'],
            ['name' => 'Dave',  'role' => 'user'],
        ]);

        $count = self::$conn->query('SELECT COUNT(*) AS c FROM integration_users')->fetch()['c'];
        $this->assertSame('3', (string) $count);
    }

    public function test_insertAll_empty_array_is_noop(): void
    {
        $result = $this->ctrl->insertAll('integration_users', []);
        $this->assertSame('success', $result);
        $this->assertSame('0', (string) self::$conn->query('SELECT COUNT(*) AS c FROM integration_users')->fetch()['c']);
    }

    public function test_update_modifies_target_row(): void
    {
        $this->ctrl->insert('integration_users', ['name' => 'Eve', 'role' => 'user']);
        $this->ctrl->update('integration_users', ['role' => 'admin'], ['name' => 'Eve']);

        $row = self::$conn->query("SELECT role FROM integration_users WHERE name = 'Eve'")->fetch();
        $this->assertSame('admin', $row['role']);
    }

    public function test_update_does_not_affect_other_rows(): void
    {
        $this->ctrl->insertAll('integration_users', [
            ['name' => 'Frank', 'role' => 'user'],
            ['name' => 'Grace', 'role' => 'user'],
        ]);

        $this->ctrl->update('integration_users', ['role' => 'admin'], ['name' => 'Frank']);

        $graceRole = self::$conn
            ->query("SELECT role FROM integration_users WHERE name = 'Grace'")
            ->fetch()['role'];

        $this->assertSame('user', $graceRole);
    }

    public function test_delete_removes_row(): void
    {
        $this->ctrl->insert('integration_users', ['name' => 'Heidi', 'role' => 'user']);
        $this->ctrl->delete('integration_users', ['name' => 'Heidi']);

        $count = self::$conn->query('SELECT COUNT(*) AS c FROM integration_users')->fetch()['c'];
        $this->assertSame('0', (string) $count);
    }

    public function test_deleteAll_removes_multiple_rows(): void
    {
        $this->ctrl->insertAll('integration_users', [
            ['name' => 'Ivan',  'role' => 'user'],
            ['name' => 'Judy',  'role' => 'user'],
            ['name' => 'Karl',  'role' => 'admin'],
        ]);

        $this->ctrl->deleteAll('integration_users', [
            ['name' => 'Ivan', 'role' => 'user'],
            ['name' => 'Judy', 'role' => 'user'],
        ]);

        $rows = self::$conn->query('SELECT name FROM integration_users')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('Karl', $rows[0]['name']);
    }

    // -------------------------------------------------------------------------
    // CtrlGlobal — SELECT helpers
    // -------------------------------------------------------------------------

    public function test_GetGlobalFilter_returns_all_rows(): void
    {
        $this->ctrl->insertAll('integration_users', [
            ['name' => 'Leo', 'role' => 'user'],
            ['name' => 'Mia', 'role' => 'admin'],
        ]);

        $rows = $this->ctrl->GetGlobalFilter('SELECT * FROM integration_users');
        $this->assertCount(2, $rows);
    }

    public function test_GetGlobalFilter_with_positional_param(): void
    {
        $this->ctrl->insertAll('integration_users', [
            ['name' => 'Ned',    'role' => 'user'],
            ['name' => 'Olivia', 'role' => 'admin'],
        ]);

        $rows = $this->ctrl->GetGlobalFilter(
            'SELECT * FROM integration_users WHERE role = ?',
            ['admin']
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Olivia', $rows[0]['name']);
    }

    public function test_GetGlobalFilter_with_named_param(): void
    {
        $this->ctrl->insert('integration_users', ['name' => 'Pat', 'role' => 'superadmin']);

        $rows = $this->ctrl->GetGlobalFilter(
            'SELECT * FROM integration_users WHERE role = :role',
            [':role' => 'superadmin']
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Pat', $rows[0]['name']);
    }

    public function test_getName_returns_name_column(): void
    {
        $this->ctrl->insert('integration_users', ['name' => 'Quinn', 'role' => 'user']);

        $name = $this->ctrl->getName(
            'SELECT name FROM integration_users WHERE role = ?',
            ['user']
        );

        $this->assertSame('Quinn', $name);
    }

    public function test_getName_returns_empty_string_when_no_rows(): void
    {
        $name = $this->ctrl->getName(
            'SELECT name FROM integration_users WHERE role = ?',
            ['ghost']
        );

        $this->assertSame('', $name);
    }

    public function test_runSql_returns_rows(): void
    {
        $this->ctrl->insertAll('integration_users', [
            ['name' => 'Rex', 'role' => 'mod'],
            ['name' => 'Sam', 'role' => 'mod'],
        ]);

        $rows = $this->ctrl->runSql(
            'SELECT * FROM integration_users WHERE role = ?',
            ['mod']
        );

        $this->assertCount(2, $rows);
    }

    public function test_rows_are_associative_arrays(): void
    {
        $this->ctrl->insert('integration_users', ['name' => 'Tina', 'role' => 'user']);

        $rows = $this->ctrl->GetGlobalFilter('SELECT * FROM integration_users');
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('role', $rows[0]);
    }

    // -------------------------------------------------------------------------
    // CtrlGlobal — encode / decode
    // -------------------------------------------------------------------------

    public function test_encode_decode_round_trip(): void
    {
        $original = 'pgsql-secret-data';
        $this->assertSame($original, $this->ctrl->decode($this->ctrl->encode($original)));
    }
}
