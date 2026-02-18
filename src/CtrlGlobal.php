<?php

namespace Greatcode\ControllerGlobal;

use PDOStatement;
use Throwable;

class CtrlGlobal
{
    protected Connection $db;

    /**
     * @param Connection|array|null $connection
     *   - Connection instance  → used directly
     *   - array                → passed to Connection::fromConfig()
     *   - null                 → Connection::fromEnv()
     */
    public function __construct(Connection|array|null $connection = null)
    {
        if ($connection instanceof Connection) {
            $this->db = $connection;
        } elseif (is_array($connection)) {
            $this->db = Connection::fromConfig($connection);
        } else {
            $this->db = Connection::fromDefaultConfig();
        }
    }

    // -------------------------------------------------------------------------
    // DML — all use prepared statements for SQL-injection safety
    // -------------------------------------------------------------------------

    /**
     * INSERT a single row.
     *
     * @param  string               $table
     * @param  array<string, mixed> $arFieldValues  column => value
     * @return string  'success'
     */
    public function insert(string $table, array $arFieldValues): string
    {
        $fields      = array_keys($arFieldValues);
        $placeholder = implode(', ', array_fill(0, count($fields), '?'));
        $sql         = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $fields),
            $placeholder
        );

        $this->db->prepare($sql)->execute(array_values($arFieldValues));

        return 'success';
    }

    /**
     * INSERT multiple rows in a single statement.
     *
     * @param  string                        $table
     * @param  array<int, array<string,mixed>> $arValues  list of column => value maps
     * @return string  'success'
     */
    public function insertAll(string $table, array $arValues): string
    {
        if (empty($arValues)) {
            return 'success';
        }

        $fields         = array_keys($arValues[0]);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';
        $sql            = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $fields),
            implode(', ', array_fill(0, count($arValues), $rowPlaceholder))
        );

        $params = [];
        foreach ($arValues as $row) {
            array_push($params, ...array_values($row));
        }

        $this->db->prepare($sql)->execute($params);

        return 'success';
    }

    /**
     * UPDATE rows matching $arConditions.
     *
     * @param  string               $table
     * @param  array<string, mixed> $arFieldValues  SET  column => value
     * @param  array<string, mixed> $arConditions   WHERE column => value  (joined with AND)
     * @return string  'success'
     */
    public function update(string $table, array $arFieldValues, array $arConditions): string
    {
        $setClauses   = implode(', ', array_map(fn($f) => "$f = ?", array_keys($arFieldValues)));
        $whereClauses = implode(' AND ', array_map(fn($f) => "$f = ?", array_keys($arConditions)));
        $sql          = "UPDATE $table SET $setClauses WHERE $whereClauses";

        $this->db->prepare($sql)->execute(
            array_merge(array_values($arFieldValues), array_values($arConditions))
        );

        return 'success';
    }

    /**
     * DELETE rows matching $arConditions (AND-joined).
     *
     * @param  string               $table
     * @param  array<string, mixed> $arConditions  column => value
     * @return string  'success'
     */
    public function delete(string $table, array $arConditions): string
    {
        $whereClauses = implode(' AND ', array_map(fn($f) => "$f = ?", array_keys($arConditions)));
        $sql          = "DELETE FROM $table WHERE $whereClauses";

        $this->db->prepare($sql)->execute(array_values($arConditions));

        return 'success';
    }

    /**
     * DELETE multiple rows, each matched by its own set of conditions (OR-joined).
     *
     * @param  string                        $table
     * @param  array<int, array<string,mixed>> $arValues  list of column => value condition maps
     * @return string  'success'
     */
    public function deleteAll(string $table, array $arValues): string
    {
        if (empty($arValues)) {
            return 'success';
        }

        $fields       = array_keys($arValues[0]);
        $rowCondition = '(' . implode(' AND ', array_map(fn($f) => "$f = ?", $fields)) . ')';
        $sql          = sprintf(
            'DELETE FROM %s WHERE %s',
            $table,
            implode(' OR ', array_fill(0, count($arValues), $rowCondition))
        );

        $params = [];
        foreach ($arValues as $row) {
            array_push($params, ...array_values($row));
        }

        $this->db->prepare($sql)->execute($params);

        return 'success';
    }

    // -------------------------------------------------------------------------
    // SELECT helpers
    // -------------------------------------------------------------------------

    /**
     * Execute a raw SELECT and return all rows as associative arrays.
     * Use only with trusted / pre-validated SQL.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Execute a SELECT and return all rows as associative arrays.
     *
     * Pass $params to use a prepared statement (recommended for dynamic values):
     *   $ctrl->GetGlobalFilter('SELECT * FROM users WHERE id = ?', [$id]);
     *   $ctrl->GetGlobalFilter('SELECT * FROM users WHERE role = :role', [':role' => 'admin']);
     *
     * @param  array<int|string, mixed> $params  Positional (?) or named (:key) bindings.
     * @return array<int, array<string, mixed>>
     */
    public function GetGlobalFilter(string $sql, array $params = []): array
    {
        if (empty($params)) {
            return $this->db->query($sql)->fetchAll();
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a raw SELECT and return the `name` column of the first row.
     */
    /**
     * @param  array<int|string, mixed> $params
     */
    public function getName(string $sql, array $params = []): string
    {
        $data = $this->GetGlobalFilter($sql, $params);
        return $data[0]['name'] ?? '';
    }

    /**
     * Execute a raw SQL statement and return all result rows.
     * Use only with trusted / pre-validated SQL.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Execute a raw SQL statement and return all result rows.
     *
     * @param  array<int|string, mixed> $params  Positional (?) or named (:key) bindings.
     * @return array<int, array<string, mixed>>
     */
    public function runSql(string $sql, array $params = []): array
    {
        if (empty($params)) {
            return $this->db->query($sql)->fetchAll();
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Input sanitisation
    // NOTE: prefer prepared statements (insert/update/delete above) over these.
    // -------------------------------------------------------------------------

    /**
     * Escape a value for safe embedding in a raw SQL string.
     * Returns the bare escaped value without surrounding quotes.
     */
    public function filterParams(mixed $params): string
    {
        $quoted = $this->db->quote((string) ($params ?? ''));
        // PDO::quote() adds surrounding single-quotes; strip them for compatibility.
        return substr($quoted, 1, -1);
    }

    /** Alias of filterParams. */
    public function encrypt_params(mixed $params): string
    {
        return $this->filterParams($params);
    }

    // -------------------------------------------------------------------------
    // Crypto (AES-256-CBC)
    // -------------------------------------------------------------------------

    public function encode(string $string): string
    {
        [$key, $iv] = $this->cryptoKeyIv();
        return base64_encode(openssl_encrypt($string, 'AES-256-CBC', $key, 0, $iv));
    }

    public function decode(string $string): string|false
    {
        [$key, $iv] = $this->cryptoKeyIv();
        return openssl_decrypt(base64_decode($string), 'AES-256-CBC', $key, 0, $iv);
    }

    /** @return array{string, string} [key, iv] */
    private function cryptoKeyIv(): array
    {
        $secret = '4R1frAgiLPaMu17KAS';
        $key    = hash('sha256', $secret);
        $iv     = substr(hash('sha256', $secret), 0, 16);
        return [$key, $iv];
    }
}
