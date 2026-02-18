<?php

namespace Greatcode\ControllerGlobal;

use PDO;
use PDOException;
use InvalidArgumentException;
use Throwable;

class Connection extends PDO
{
    private static ?self $instance = null;

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

        parent::__construct($dsn, $username, $password, array_replace($defaults, $options));
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
     * Create a connection from a config array.
     *
     * Supported keys: driver, host, port, dbname, charset, username, password, options
     * Supported drivers: mysql, pgsql, sqlite
     */
    public static function fromConfig(array $config): static
    {
        $driver   = $config['driver']   ?? 'mysql';
        $host     = $config['host']     ?? '127.0.0.1';
        $port     = (int) ($config['port']    ?? 3306);
        $dbname   = $config['dbname']   ?? '';
        $charset  = $config['charset']  ?? 'utf8mb4';
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $options  = $config['options']  ?? [];

        $dsn = match ($driver) {
            'mysql'  => "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}",
            'pgsql'  => "pgsql:host={$host};port={$port};dbname={$dbname}",
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
     * Create a connection from default config.
     *
     */
    public static function fromDefaultConfig(): static
    {
        global $cfg;
        $driver  = @$cfg['db']['driver'] ?? 'mysql';
        $host    = @$cfg['db']['host'] ?? '127.0.0.1';
        $userdb  = @$cfg['db']['user'] ?? 'root';
        $passdb  = @$cfg['db']['password'] ?? '';
        $namedb  = @$cfg['db']['name'] ?? '';

        return static::fromConfig([
            'driver'   => $driver,
            'host'     => $host,
            'port'     => 3306,
            'dbname'   => $namedb,
            'charset'  => 'utf8mb4',
            'username' => $userdb,
            'password' => $passdb,
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
