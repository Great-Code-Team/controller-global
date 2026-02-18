<?php

namespace Greatcode\ControllerGlobal\Tests\Integration;

use Greatcode\ControllerGlobal\Connection;
use Greatcode\ControllerGlobal\CtrlGlobal;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration tests against a real MySQL server.
 *
 * Required env vars:
 *   DB_NAME      – database to connect to (skipped when absent)
 *
 * Optional env vars (with defaults):
 *   DB_HOST      – default 127.0.0.1
 *   DB_PORT      – default 3306
 *   DB_USER      – default root
 *   DB_PASSWORD  – default (empty)
 *
 * @requires extension pdo_mysql
 */
class MysqlIntegrationTest extends TestCase
{
    private static ?Connection $conn = null;
    private CtrlGlobal $ctrl;

    // -------------------------------------------------------------------------
    // Suite bootstrap / teardown
    // -------------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        $name = getenv('DB_NAME') ?: '';

        if ($name === '') {
            self::markTestSkipped(
                'Set DB_NAME (+ DB_HOST, DB_PORT, DB_USER, DB_PASSWORD) to run MySQL integration tests.'
            );
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASSWORD') ?: '';

        try {
            self::$conn = Connection::fromConfig([
                'driver'   => 'mysql',
                'host'     => $host,
                'port'     => $port,
                'dbname'   => $name,
                'username' => $user,
                'password' => $pass,
            ]);
        } catch (PDOException $e) {
            self::markTestSkipped("Could not connect to MySQL: {$e->getMessage()}");
        }

        self::$conn->exec('
            CREATE TABLE IF NOT EXISTS integration_users (
                id   INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                role VARCHAR(50)  NOT NULL DEFAULT \'user\',
                age  INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    public static function tearDownAfterClass(): void
    {
        self::$conn?->exec('DROP TABLE IF EXISTS integration_users');
    }

    protected function setUp(): void
    {
        self::$conn->exec('TRUNCATE TABLE integration_users');
        $this->ctrl = new CtrlGlobal(self::$conn);
    }

    // -------------------------------------------------------------------------
    // Connection — attributes
    // -------------------------------------------------------------------------

    public function test_fromConfig_mysql_returns_connection(): void
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
        // MySQL supports this attribute natively (unlike SQLite)
        $this->assertFalse((bool) self::$conn->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    // -------------------------------------------------------------------------
    // Connection — fromEnv()
    // -------------------------------------------------------------------------

    public function test_fromEnv_connects_with_mysql_env_vars(): void
    {
        // DB_* env vars are set by CI (or the developer) for this suite
        $conn = Connection::fromEnv();
        $this->assertInstanceOf(Connection::class, $conn);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $conn->getAttribute(PDO::ATTR_ERRMODE));
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
        $result = self::$conn->transaction(fn() => 'mysql-ok');
        $this->assertSame('mysql-ok', $result);
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
            ['name' => 'Leo',  'role' => 'user'],
            ['name' => 'Mia',  'role' => 'admin'],
        ]);

        $rows = $this->ctrl->GetGlobalFilter('SELECT * FROM integration_users');
        $this->assertCount(2, $rows);
    }

    public function test_GetGlobalFilter_with_positional_param(): void
    {
        $this->ctrl->insertAll('integration_users', [
            ['name' => 'Ned',   'role' => 'user'],
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
        $original = 'mysql-secret-data';
        $this->assertSame($original, $this->ctrl->decode($this->ctrl->encode($original)));
    }
}
