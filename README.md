# controller-global

Base Library Controller Global for GC — a PHP utility library providing a PDO wrapper, generic DML/SELECT helpers, AES-256-CBC encoding, and a POS-group integrator client.

**Requirements:** PHP >= 8.2, ext-pdo, ext-pdo_sqlite, ext-openssl, ext-curl, guzzlehttp/guzzle ^7.10

## Installation

```bash
composer require greatcode/controller-global
```

---

## Connection

`Connection` extends `PDO` with safe defaults and convenience factory methods.

**Default PDO attributes set on every connection:**

| Attribute | Value |
|---|---|
| `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` |
| `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` |
| `ATTR_EMULATE_PREPARES` | `false` |
| `ATTR_PERSISTENT` | `false` |

### Direct constructor

```php
use Greatcode\ControllerGlobal\Connection;

$conn = new Connection('mysql:host=127.0.0.1;dbname=mydb;charset=utf8mb4', 'user', 'pass');
```

Override any default attribute:

```php
$conn = new Connection('sqlite::memory:', options: [PDO::ATTR_EMULATE_PREPARES => true]);
```

### fromConfig()

Build a connection from an associative array. Supported drivers: `mysql`, `pgsql`, `sqlite`.

```php
$conn = Connection::fromConfig([
    'driver'   => 'mysql',       // default: 'mysql'
    'host'     => '127.0.0.1',   // default: 'localhost'
    'port'     => 3306,
    'dbname'   => 'mydb',
    'charset'  => 'utf8mb4',     // default: 'utf8mb4'
    'username' => 'user',
    'password' => 'pass',
    'options'  => [],            // extra PDO attributes
]);

// SQLite in-memory
$conn = Connection::fromConfig(['driver' => 'sqlite', 'dbname' => ':memory:']);
```

### fromEnv()

Reads connection parameters from environment variables.

| Env var | Default |
|---|---|
| `DB_DRIVER` | `mysql` |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_NAME` | _(empty)_ |
| `DB_CHARSET` | `utf8mb4` |
| `DB_USER` | _(empty)_ |
| `DB_PASSWORD` | _(empty)_ |

```php
$conn = Connection::fromEnv();
```

### getInstance() — singleton

Returns the same `Connection` instance across calls. Useful for sharing a single connection throughout a request lifecycle.

```php
$conn = Connection::getInstance('mysql:host=127.0.0.1;dbname=mydb', 'user', 'pass');

// Subsequent calls with any arguments return the first instance
$same = Connection::getInstance();
```

### transaction()

Wraps a callable in a database transaction. Commits on success, rolls back and re-throws on any exception.

```php
$conn->transaction(function (Connection $db) {
    $db->exec("INSERT INTO orders (total) VALUES (100)");
    $db->exec("UPDATE stock SET qty = qty - 1 WHERE id = 5");
});

// Return values are passed through
$id = $conn->transaction(fn(Connection $db) => $db->lastInsertId());
```

---

## CtrlGlobal

General-purpose controller with DML helpers, SELECT wrappers, HTTP client wrappers, and AES-256-CBC encode/decode. Resolves its `Connection` automatically.

### Instantiation

```php
use Greatcode\ControllerGlobal\CtrlGlobal;

// From a Connection instance
$ctrl = new CtrlGlobal($connection);

// From a config array (passed to Connection::fromConfig)
$ctrl = new CtrlGlobal(['driver' => 'sqlite', 'dbname' => ':memory:']);

// With a custom AES encryption key
$ctrl = new CtrlGlobal($connection, 'my-secret-key');

// From environment variables / global $cfg array
$ctrl = new CtrlGlobal();

// Singleton
$ctrl = CtrlGlobal::getInstance();
```

When constructed with `null` (default), it checks for a global `$cfg['db']` array first, then falls back to `Connection::fromEnv()`.

The encryption key is resolved in this order:
1. Explicit `$encryption_key` constructor argument
2. `ENCRYPTION_KEY` environment variable
3. Fallback value `'secret'`

### DML

#### insert()

```php
$ctrl->insert('users', [
    'name'  => 'Alice',
    'email' => 'alice@example.com',
]);
// returns 'success'
```

#### insertAll()

Insert multiple rows in a single statement.

```php
$ctrl->insertAll('users', [
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob',   'email' => 'bob@example.com'],
]);
```

#### update()

```php
$ctrl->update(
    'users',
    ['email' => 'new@example.com'],  // SET
    ['id'    => 42]                  // WHERE (AND-joined)
);
```

#### delete()

```php
$ctrl->delete('users', ['id' => 42]);
```

#### deleteAll()

Delete multiple rows, each matched by its own condition set (conditions within a row are AND-joined; rows are OR-joined).

```php
$ctrl->deleteAll('users', [
    ['id' => 1],
    ['id' => 2],
]);
```

### SELECT

#### GetGlobalFilter()

Execute a SELECT and return all rows as associative arrays. Supports both positional (`?`) and named (`:key`) bindings.

```php
$rows = $ctrl->GetGlobalFilter('SELECT * FROM users WHERE active = ?', [1]);

$rows = $ctrl->GetGlobalFilter(
    'SELECT * FROM users WHERE role = :role',
    [':role' => 'admin']
);
```

#### runSql()

Same behaviour as `GetGlobalFilter` — execute SQL and return all rows.

```php
$rows = $ctrl->runSql('SELECT * FROM orders WHERE status = ?', ['pending']);
```

#### getName()

Return the `name` column of the first result row, or an empty string if no rows match.

```php
$name = $ctrl->getName('SELECT name FROM categories WHERE id = ?', [5]);
```

### Encoding

AES-256-CBC encode/decode. The key is determined by the constructor (see Instantiation above).

```php
$token   = $ctrl->encode('sensitive-value');
$decoded = $ctrl->decode($token); // 'sensitive-value'
```

Two instances using different keys will produce different ciphertext for the same input and cannot decode each other's output.

### HTTP client

Thin wrappers around a shared `GuzzleHttp\Client` singleton. All methods return a PSR-7 `ResponseInterface`.

```php
$response = $ctrl->httpGet('https://api.example.com/items');
$response = $ctrl->httpPost('https://api.example.com/items', ['json' => ['name' => 'foo']]);
$response = $ctrl->httpPut('https://api.example.com/items/1', ['json' => ['name' => 'bar']]);
$response = $ctrl->httpPatch('https://api.example.com/items/1', ['json' => ['name' => 'baz']]);
$response = $ctrl->httpDelete('https://api.example.com/items/1');
```

The underlying `GuzzleHttp\Client` instance is a static singleton shared across all `CtrlGlobal` instances:

```php
$client = $ctrl->getHttpClient(); // GuzzleHttp\Client
```

---

## CtrlGroupPos

HTTP client for a remote POS-group integrator service.

```php
use Greatcode\ControllerGlobal\CtrlGroupPos;

$pos = new CtrlGroupPos('PC-001', 'https://integrator.example.com');
```

### Methods

| Method | Description |
|---|---|
| `getGroupPos(array $params)` | Query POS user data. Required keys: `group_pos`, `browser`, `waktu`. |
| `updateLoginProcess($id, $status)` | Update the login status of a POS session. |
| `updateLastDate($id, $last_data)` | Update the last-data timestamp for a POS entry. |
| `updatePOSLastDate(array $datas)` | Bulk-update POS last dates via JSON POST. Each item needs `status` and `data`. |
| `updatePosToken($token, $id, $status)` | Update the token associated with a POS session. |
| `updateBranchId($branchID, $id)` | Update the branch ID for a POS entry. |
| `saveToLocal($table, $arFieldValues)` | Insert rows into a local table via `CtrlGlobal::insertAll()`. Returns `bool`. |

---

## Running tests

```bash
composer install
./vendor/bin/phpunit
```
