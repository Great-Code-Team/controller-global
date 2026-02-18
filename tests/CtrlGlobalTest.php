<?php

namespace Greatcode\ControllerGlobal\Tests;

use Greatcode\ControllerGlobal\Connection;
use Greatcode\ControllerGlobal\CtrlGlobal;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class CtrlGlobalTest extends TestCase
{
    private Connection $conn;
    private CtrlGlobal $ctrl;

    protected function setUp(): void
    {
        $this->conn = new Connection('sqlite::memory:');
        $this->conn->exec('
            CREATE TABLE users (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT    NOT NULL,
                role TEXT    NOT NULL DEFAULT "user",
                age  INTEGER
            )
        ');
        $this->ctrl = new CtrlGlobal($this->conn);
    }

    protected function tearDown(): void
    {
        // Reset static singletons between tests
        $http = new ReflectionProperty(CtrlGlobal::class, 'httpClient');
        $http->setValue(null, null);

        $inst = new ReflectionProperty(CtrlGlobal::class, 'instance');
        $inst->setValue(null, null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function allUsers(): array
    {
        return $this->conn->query('SELECT * FROM users ORDER BY id')->fetchAll();
    }

    private function countUsers(): int
    {
        return (int) $this->conn->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function test_constructor_accepts_connection_instance(): void
    {
        $ctrl = new CtrlGlobal($this->conn);
        $this->assertInstanceOf(CtrlGlobal::class, $ctrl);
    }

    public function test_constructor_accepts_array_config(): void
    {
        $ctrl = new CtrlGlobal(['driver' => 'sqlite', 'dbname' => ':memory:']);
        $this->assertInstanceOf(CtrlGlobal::class, $ctrl);
    }

    public function test_constructor_accepts_null_with_env_vars(): void
    {
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=:memory:');

        $ctrl = new CtrlGlobal();
        $this->assertInstanceOf(CtrlGlobal::class, $ctrl);

        putenv('DB_DRIVER');
        putenv('DB_NAME');
    }

    public function test_constructor_accepts_custom_encryption_key(): void
    {
        $ctrl = new CtrlGlobal($this->conn, 'my-secret-key');
        $this->assertInstanceOf(CtrlGlobal::class, $ctrl);
    }

    public function test_encryption_key_affects_encode_output(): void
    {
        $ctrlA = new CtrlGlobal($this->conn, 'key-a');
        $ctrlB = new CtrlGlobal($this->conn, 'key-b');

        $this->assertNotSame($ctrlA->encode('hello'), $ctrlB->encode('hello'));
    }

    public function test_encryption_key_from_env(): void
    {
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=:memory:');
        putenv('ENCRYPTION_KEY=env-key');

        $ctrl = new CtrlGlobal();
        $encoded = $ctrl->encode('data');
        $this->assertSame('data', $ctrl->decode($encoded));

        putenv('DB_DRIVER');
        putenv('DB_NAME');
        putenv('ENCRYPTION_KEY');
    }

    // -------------------------------------------------------------------------
    // insert()
    // -------------------------------------------------------------------------

    public function test_insert_returns_success(): void
    {
        $result = $this->ctrl->insert('users', ['name' => 'Alice', 'role' => 'admin']);
        $this->assertSame('success', $result);
    }

    public function test_insert_persists_row(): void
    {
        $this->ctrl->insert('users', ['name' => 'Bob', 'role' => 'user']);

        $users = $this->allUsers();
        $this->assertCount(1, $users);
        $this->assertSame('Bob', $users[0]['name']);
        $this->assertSame('user', $users[0]['role']);
    }

    public function test_insert_persists_all_fields(): void
    {
        $this->ctrl->insert('users', ['name' => 'Carol', 'role' => 'mod', 'age' => 30]);

        $row = $this->allUsers()[0];
        $this->assertSame('Carol', $row['name']);
        $this->assertSame('mod', $row['role']);
        $this->assertSame('30', (string) $row['age']);
    }

    public function test_insert_multiple_rows_independently(): void
    {
        $this->ctrl->insert('users', ['name' => 'Dave', 'role' => 'user']);
        $this->ctrl->insert('users', ['name' => 'Eve', 'role' => 'admin']);

        $this->assertCount(2, $this->allUsers());
    }

    // -------------------------------------------------------------------------
    // insertAll()
    // -------------------------------------------------------------------------

    public function test_insertAll_returns_success_on_empty_array(): void
    {
        $this->assertSame('success', $this->ctrl->insertAll('users', []));
    }

    public function test_insertAll_does_not_insert_on_empty_array(): void
    {
        $this->ctrl->insertAll('users', []);
        $this->assertSame(0, $this->countUsers());
    }

    public function test_insertAll_inserts_all_rows(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Frank', 'role' => 'user'],
            ['name' => 'Grace', 'role' => 'admin'],
            ['name' => 'Heidi', 'role' => 'mod'],
        ]);

        $this->assertSame(3, $this->countUsers());
    }

    public function test_insertAll_returns_success(): void
    {
        $result = $this->ctrl->insertAll('users', [
            ['name' => 'Ivan', 'role' => 'user'],
        ]);
        $this->assertSame('success', $result);
    }

    public function test_insertAll_persists_correct_values(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Judy', 'role' => 'admin'],
            ['name' => 'Karl', 'role' => 'user'],
        ]);

        $users = $this->allUsers();
        $this->assertSame('Judy', $users[0]['name']);
        $this->assertSame('admin', $users[0]['role']);
        $this->assertSame('Karl', $users[1]['name']);
        $this->assertSame('user', $users[1]['role']);
    }

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    public function test_update_returns_success(): void
    {
        $this->ctrl->insert('users', ['name' => 'Leo', 'role' => 'user']);
        $result = $this->ctrl->update('users', ['role' => 'admin'], ['name' => 'Leo']);
        $this->assertSame('success', $result);
    }

    public function test_update_modifies_target_row(): void
    {
        $this->ctrl->insert('users', ['name' => 'Mia', 'role' => 'user']);
        $this->ctrl->update('users', ['role' => 'admin'], ['name' => 'Mia']);

        $row = $this->allUsers()[0];
        $this->assertSame('admin', $row['role']);
    }

    public function test_update_does_not_affect_other_rows(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Ned',  'role' => 'user'],
            ['name' => 'Olivia', 'role' => 'user'],
        ]);

        $this->ctrl->update('users', ['role' => 'admin'], ['name' => 'Ned']);

        $users = $this->allUsers();
        $this->assertSame('admin', $users[0]['role']);
        $this->assertSame('user',  $users[1]['role']);
    }

    public function test_update_with_multiple_conditions(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Pat', 'role' => 'user',  'age' => 25],
            ['name' => 'Pat', 'role' => 'admin', 'age' => 35],
        ]);

        $this->ctrl->update('users', ['age' => 26], ['name' => 'Pat', 'role' => 'user']);

        $users = $this->conn->query(
            'SELECT age FROM users WHERE name = "Pat" AND role = "user"'
        )->fetchAll();

        $this->assertSame('26', (string) $users[0]['age']);

        $adminAge = $this->conn->query(
            'SELECT age FROM users WHERE name = "Pat" AND role = "admin"'
        )->fetch()['age'];
        $this->assertSame('35', (string) $adminAge);
    }

    public function test_update_multiple_fields_at_once(): void
    {
        $this->ctrl->insert('users', ['name' => 'Quinn', 'role' => 'user', 'age' => 20]);
        $this->ctrl->update('users', ['role' => 'admin', 'age' => 21], ['name' => 'Quinn']);

        $row = $this->allUsers()[0];
        $this->assertSame('admin', $row['role']);
        $this->assertSame('21', (string) $row['age']);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function test_delete_returns_success(): void
    {
        $this->ctrl->insert('users', ['name' => 'Rex', 'role' => 'user']);
        $result = $this->ctrl->delete('users', ['name' => 'Rex']);
        $this->assertSame('success', $result);
    }

    public function test_delete_removes_target_row(): void
    {
        $this->ctrl->insert('users', ['name' => 'Sam', 'role' => 'user']);
        $this->ctrl->delete('users', ['name' => 'Sam']);

        $this->assertSame(0, $this->countUsers());
    }

    public function test_delete_does_not_remove_other_rows(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Tina', 'role' => 'user'],
            ['name' => 'Uma',  'role' => 'user'],
        ]);

        $this->ctrl->delete('users', ['name' => 'Tina']);

        $users = $this->allUsers();
        $this->assertCount(1, $users);
        $this->assertSame('Uma', $users[0]['name']);
    }

    public function test_delete_with_multiple_conditions(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Vic', 'role' => 'user'],
            ['name' => 'Vic', 'role' => 'admin'],
        ]);

        $this->ctrl->delete('users', ['name' => 'Vic', 'role' => 'user']);

        $users = $this->allUsers();
        $this->assertCount(1, $users);
        $this->assertSame('admin', $users[0]['role']);
    }

    // -------------------------------------------------------------------------
    // deleteAll()
    // -------------------------------------------------------------------------

    public function test_deleteAll_returns_success_on_empty_array(): void
    {
        $this->assertSame('success', $this->ctrl->deleteAll('users', []));
    }

    public function test_deleteAll_does_not_delete_on_empty_array(): void
    {
        $this->ctrl->insert('users', ['name' => 'Wes', 'role' => 'user']);
        $this->ctrl->deleteAll('users', []);
        $this->assertSame(1, $this->countUsers());
    }

    public function test_deleteAll_returns_success(): void
    {
        $this->ctrl->insert('users', ['name' => 'Xena', 'role' => 'user']);
        $result = $this->ctrl->deleteAll('users', [['name' => 'Xena', 'role' => 'user']]);
        $this->assertSame('success', $result);
    }

    public function test_deleteAll_removes_multiple_rows(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Yara',  'role' => 'user'],
            ['name' => 'Zack',  'role' => 'user'],
            ['name' => 'Alpha', 'role' => 'admin'],
        ]);

        $this->ctrl->deleteAll('users', [
            ['name' => 'Yara', 'role' => 'user'],
            ['name' => 'Zack', 'role' => 'user'],
        ]);

        $remaining = $this->allUsers();
        $this->assertCount(1, $remaining);
        $this->assertSame('Alpha', $remaining[0]['name']);
    }

    public function test_deleteAll_does_not_remove_non_matching_rows(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Beta',  'role' => 'user'],
            ['name' => 'Gamma', 'role' => 'admin'],
        ]);

        $this->ctrl->deleteAll('users', [['name' => 'Beta', 'role' => 'user']]);

        $remaining = $this->allUsers();
        $this->assertCount(1, $remaining);
        $this->assertSame('Gamma', $remaining[0]['name']);
    }

    // -------------------------------------------------------------------------
    // GetGlobalFilter()
    // -------------------------------------------------------------------------

    public function test_GetGlobalFilter_returns_all_rows(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Delta',   'role' => 'user'],
            ['name' => 'Epsilon', 'role' => 'admin'],
        ]);

        $rows = $this->ctrl->GetGlobalFilter('SELECT * FROM users');
        $this->assertCount(2, $rows);
    }

    public function test_GetGlobalFilter_returns_empty_on_no_match(): void
    {
        $rows = $this->ctrl->GetGlobalFilter('SELECT * FROM users WHERE role = ?', ['ghost']);
        $this->assertSame([], $rows);
    }

    public function test_GetGlobalFilter_with_positional_params(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Zeta', 'role' => 'user'],
            ['name' => 'Eta',  'role' => 'admin'],
        ]);

        $rows = $this->ctrl->GetGlobalFilter('SELECT * FROM users WHERE role = ?', ['admin']);
        $this->assertCount(1, $rows);
        $this->assertSame('Eta', $rows[0]['name']);
    }

    public function test_GetGlobalFilter_with_named_params(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Theta', 'role' => 'user'],
            ['name' => 'Iota',  'role' => 'admin'],
        ]);

        $rows = $this->ctrl->GetGlobalFilter(
            'SELECT * FROM users WHERE role = :role',
            [':role' => 'user']
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Theta', $rows[0]['name']);
    }

    public function test_GetGlobalFilter_rows_are_associative_arrays(): void
    {
        $this->ctrl->insert('users', ['name' => 'Kappa', 'role' => 'user']);

        $rows = $this->ctrl->GetGlobalFilter('SELECT * FROM users');
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('role', $rows[0]);
    }

    public function test_GetGlobalFilter_with_multiple_positional_params(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Lambda', 'role' => 'user',  'age' => 20],
            ['name' => 'Mu',     'role' => 'user',  'age' => 30],
            ['name' => 'Nu',     'role' => 'admin', 'age' => 20],
        ]);

        $rows = $this->ctrl->GetGlobalFilter(
            'SELECT * FROM users WHERE role = ? AND age = ?',
            ['user', 20]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Lambda', $rows[0]['name']);
    }

    // -------------------------------------------------------------------------
    // getName()
    // -------------------------------------------------------------------------

    public function test_getName_returns_name_of_first_row(): void
    {
        $this->ctrl->insert('users', ['name' => 'Xi', 'role' => 'user']);
        $name = $this->ctrl->getName('SELECT name FROM users');
        $this->assertSame('Xi', $name);
    }

    public function test_getName_returns_empty_string_when_no_rows(): void
    {
        $name = $this->ctrl->getName('SELECT name FROM users WHERE role = ?', ['ghost']);
        $this->assertSame('', $name);
    }

    public function test_getName_with_positional_param(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Omicron', 'role' => 'user'],
            ['name' => 'Pi',      'role' => 'admin'],
        ]);

        $name = $this->ctrl->getName('SELECT name FROM users WHERE role = ?', ['admin']);
        $this->assertSame('Pi', $name);
    }

    public function test_getName_with_named_param(): void
    {
        $this->ctrl->insert('users', ['name' => 'Rho', 'role' => 'mod']);

        $name = $this->ctrl->getName(
            'SELECT name FROM users WHERE role = :role',
            [':role' => 'mod']
        );
        $this->assertSame('Rho', $name);
    }

    public function test_getName_returns_first_row_when_multiple_match(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Sigma', 'role' => 'user'],
            ['name' => 'Tau',   'role' => 'user'],
        ]);

        $name = $this->ctrl->getName('SELECT name FROM users WHERE role = ? ORDER BY id', ['user']);
        $this->assertSame('Sigma', $name);
    }

    // -------------------------------------------------------------------------
    // runSql()
    // -------------------------------------------------------------------------

    public function test_runSql_without_params_returns_rows(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Upsilon', 'role' => 'user'],
            ['name' => 'Phi',     'role' => 'admin'],
        ]);

        $rows = $this->ctrl->runSql('SELECT * FROM users');
        $this->assertCount(2, $rows);
    }

    public function test_runSql_with_positional_params(): void
    {
        $this->ctrl->insertAll('users', [
            ['name' => 'Chi', 'role' => 'user'],
            ['name' => 'Psi', 'role' => 'admin'],
        ]);

        $rows = $this->ctrl->runSql('SELECT * FROM users WHERE role = ?', ['user']);
        $this->assertCount(1, $rows);
        $this->assertSame('Chi', $rows[0]['name']);
    }

    public function test_runSql_with_named_params(): void
    {
        $this->ctrl->insert('users', ['name' => 'Omega', 'role' => 'superadmin']);

        $rows = $this->ctrl->runSql(
            'SELECT * FROM users WHERE name = :name',
            [':name' => 'Omega']
        );

        $this->assertCount(1, $rows);
        $this->assertSame('superadmin', $rows[0]['role']);
    }

    public function test_runSql_returns_empty_on_no_match(): void
    {
        $rows = $this->ctrl->runSql('SELECT * FROM users WHERE role = ?', ['ghost']);
        $this->assertSame([], $rows);
    }

    public function test_runSql_returns_associative_arrays(): void
    {
        $this->ctrl->insert('users', ['name' => 'Ares', 'role' => 'user']);
        $rows = $this->ctrl->runSql('SELECT * FROM users');
        $this->assertArrayHasKey('name', $rows[0]);
    }

    // -------------------------------------------------------------------------
    // getHttpClient()
    // -------------------------------------------------------------------------

    public function test_getHttpClient_returns_guzzle_client(): void
    {
        $this->assertInstanceOf(Client::class, $this->ctrl->getHttpClient());
    }

    public function test_getHttpClient_returns_same_instance(): void
    {
        $a = $this->ctrl->getHttpClient();
        $b = $this->ctrl->getHttpClient();
        $this->assertSame($a, $b);
    }

    public function test_getHttpClient_singleton_shared_across_instances(): void
    {
        $ctrlA = new CtrlGlobal($this->conn);
        $ctrlB = new CtrlGlobal($this->conn);
        $this->assertSame($ctrlA->getHttpClient(), $ctrlB->getHttpClient());
    }

    // -------------------------------------------------------------------------
    // encode() / decode()
    // -------------------------------------------------------------------------

    public function test_encode_returns_non_empty_string(): void
    {
        $this->assertNotEmpty($this->ctrl->encode('hello'));
    }

    public function test_encode_output_is_valid_base64(): void
    {
        $encoded = $this->ctrl->encode('test input');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $encoded);
    }

    public function test_decode_reverses_encode(): void
    {
        $original = 'Hello, World!';
        $this->assertSame($original, $this->ctrl->decode($this->ctrl->encode($original)));
    }

    public function test_encode_different_inputs_produce_different_outputs(): void
    {
        $this->assertNotSame(
            $this->ctrl->encode('foo'),
            $this->ctrl->encode('bar')
        );
    }

    public function test_encode_same_input_produces_same_output(): void
    {
        $this->assertSame(
            $this->ctrl->encode('consistent'),
            $this->ctrl->encode('consistent')
        );
    }

    public function test_encode_decode_empty_string(): void
    {
        $this->assertSame('', $this->ctrl->decode($this->ctrl->encode('')));
    }

    public function test_encode_decode_unicode_string(): void
    {
        $original = 'こんにちは世界';
        $this->assertSame($original, $this->ctrl->decode($this->ctrl->encode($original)));
    }

    public function test_encode_decode_long_string(): void
    {
        $original = str_repeat('The quick brown fox jumps over the lazy dog. ', 100);
        $this->assertSame($original, $this->ctrl->decode($this->ctrl->encode($original)));
    }

    public function test_encode_decode_special_characters(): void
    {
        $original = "!@#\$%^&*()_+-=[]{}|;':\",./<>?`~\\";
        $this->assertSame($original, $this->ctrl->decode($this->ctrl->encode($original)));
    }
}
