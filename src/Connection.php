<?php

namespace Greatcode\ControllerGlobal;

use PDO;
use PDOException;
use InvalidArgumentException;
use Throwable;

class Connection extends PDO
{
    private static ?self $instance = null;

    /** Tracks the options passed to the constructor for drivers that don't support getAttribute(). */
    private array $pdoOptions = [];

    public function __construct(
        string $dsn,
        string $username = '',
        #[\SensitiveParameter] string $password = '',
        array $options = []
    ) {
        $defaults = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        $this->pdoOptions = array_replace($defaults, $options);
        parent::__construct($dsn, $username, $password, $this->pdoOptions);
    }

    /**
     * Get or create a singleton instance.
     */
    public static function getInstance(
        string $dsn = '',
        string $username = '',
        #[\SensitiveParameter] string $password = '',
        array $options = []
    ): static {
        if (static::$instance === null) {
            static::$instance = new static($dsn, $username, $password, $options);
        }

        return static::$instance;
    }

    /**
     * Falls back to the stored constructor options when the driver doesn't support
     * getAttribute() for a given attribute (e.g. SQLite + ATTR_EMULATE_PREPARES).
     */
    public function getAttribute(int $attribute): mixed
    {
        try {
            return parent::getAttribute($attribute);
        } catch (PDOException $e) {
            if (array_key_exists($attribute, $this->pdoOptions)) {
                return $this->pdoOptions[$attribute];
            }
            throw $e;
        }
    }

    /**
     * Create a connection from a config array.
     *
     * Supported keys: driver, host, port, dbname, charset, username, password, options
     * Supported drivers: mysql, pgsql, sqlite
     */
    public static function fromConfig(array $config): static
    {
        $driver   = $config['driver']   ?? 'mysql';
        $host     = $config['host']     ?? 'localhost';
        $port     = $config['port']     ?? '';
        $dbname   = $config['dbname']   ?? '';
        $charset  = $config['charset']  ?? 'utf8mb4';
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $options  = $config['options']  ?? [];

        $dsn = match ($driver) {
            'mysql'  => "mysql:host={$host};port=" . empty($port) ? "3306" : $port . ";dbname={$dbname};charset={$charset}",
            'pgsql'  => "pgsql:host={$host};port=" . empty($port) ? "5432" : $port . ";dbname={$dbname};charset={$charset}",
            'sqlite' => "sqlite:{$dbname}",
            default  => throw new InvalidArgumentException("Unsupported driver: {$driver}"),
        };

        return new static($dsn, $username, $password, $options);
    }

    /**
     * Create a connection from environment variables.
     *
     * Expected env vars: DB_DRIVER, DB_HOST, DB_PORT, DB_NAME, DB_CHARSET, DB_USER, DB_PASSWORD
     */
    public static function fromEnv(): static
    {
        return static::fromConfig([
            'driver'   => getenv('DB_DRIVER')   ?: 'mysql',
            'host'     => getenv('DB_HOST')     ?: '127.0.0.1',
            'port'     => (int) (getenv('DB_PORT') ?: 3306),
            'dbname'   => getenv('DB_NAME')     ?: '',
            'charset'  => getenv('DB_CHARSET')  ?: 'utf8mb4',
            'username' => getenv('DB_USER')     ?: '',
            'password' => getenv('DB_PASSWORD') ?: '',
        ]);
    }

    /**
     * Execute a callable inside a database transaction.
     * Automatically commits on success and rolls back on any exception.
     *
     * @throws Throwable Re-throws whatever the callable throws after rolling back.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }
}
