<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

use Closure;

/**
 * Adapter for MySQL.
 *
 * @package ActiveRecord
 */
class MysqlAdapter extends Connection
{
    public static $DEFAULT_PORT = 3306;

    /**
     * @var int
     */
    public static $MAX_BIND_PARAMS = 65535;

    public static $datetime_format = 'Y-m-d H:i:s';

    public function limit($sql, $offset, $limit)
    {
        $offset = is_null($offset) ? '' : intval($offset) . ',';
        $limit = intval($limit);
        return "$sql LIMIT {$offset}$limit";
    }

    public function offset_without_limit(string $sql, int $offset): string
    {
        // MySQL has no OFFSET without LIMIT; its documented idiom is the largest row count
        return "$sql LIMIT $offset, 18446744073709551615";
    }

    /**
     * The PDO attribute that turns the buffering of query results on or off: Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, which
     * PHP 8.4 introduced, else its deprecated PDO::MYSQL_ATTR_USE_BUFFERED_QUERY (named through constant(), so that PHP
     * 8.5 never touches the deprecated name).
     *
     * @internal Serves unbuffered() and its tests; not a supported API.
     */
    public static function buffered_query_attribute(): int
    {
        return defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
            ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
            : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    }

    /**
     * Runs $execute with the buffering of query results turned off, so that the server sends the rows as they are
     * fetched, and puts the buffering back as it was, even when $execute throws. (The attribute of a single prepare()
     * is ignored by the driver: only the connection's attribute at execute() counts.)
     *
     * @internal Serves stream_query(); not a supported API.
     * @template T
     * @param Closure(): T $execute
     * @return T
     */
    protected function unbuffered(Closure $execute): mixed
    {
        $attribute = static::buffered_query_attribute();
        $previous = $this->connection->getAttribute($attribute);
        $this->connection->setAttribute($attribute, false);

        try {
            return $execute();
        } finally {
            $this->connection->setAttribute($attribute, $previous);
        }
    }

    public function query_column_info($table)
    {
        return $this->query("SHOW COLUMNS FROM $table");
    }

    /**
     * InnoDB's _rowid: another name of a table's single integer primary key column.
     *
     * @internal Serves QueryCompiler and Table; not a supported API.
     * @return list<string>
     */
    public function pseudo_columns(): array
    {
        return ['_rowid'];
    }

    public function query_for_tables()
    {
        return $this->query('SHOW TABLES');
    }

    /**
     * @param array{field: string, type: string, null: string, key: string, default: string|null, extra: string} $column
     *   One row from SHOW COLUMNS FROM (PDO::ATTR_CASE_LOWER lower-cases the driver's Field/Type/Null/Key/Default/Extra keys).
     * @return Column
     */
    public function create_column(&$column)
    {
        $c = new Column();
        $c->inflected_name	= Inflector::instance()->variablize($column['field']);
        $c->name			= $column['field'];
        $c->nullable		= ($column['null'] === 'YES' ? true : false);
        $c->pk				= ($column['key'] === 'PRI' ? true : false);
        $c->auto_increment	= ($column['extra'] === 'auto_increment' ? true : false);

        if ($column['type'] == 'timestamp' || $column['type'] == 'datetime') {
            $c->raw_type = 'datetime';
            $c->length = 19;
        } elseif ($column['type'] == 'date') {
            $c->raw_type = 'date';
            $c->length = 10;
        } elseif ($column['type'] == 'time') {
            $c->raw_type = 'time';
            $c->length = 8;
        } else {
            preg_match('/^([A-Za-z0-9_]+)(\(([0-9]+(,[0-9]+)?)\))?/', $column['type'], $matches);

            $c->raw_type = (count($matches) > 0 ? $matches[1] : $column['type']);

            if (count($matches) >= 4) {
                $c->length = intval($matches[3]);
            }
        }

        $c->map_raw_type();

        // Rails-style convention: tinyint(1) — exactly display width 1, the
        // expansion of MySQL's own BOOLEAN DDL alias — is a boolean column.
        // Wider tinyints keep INTEGER semantics, and so does an AUTO_INCREMENT
        // tinyint(1): a counter is never a boolean (#164). (MySQL 8.0.19+ drops
        // display widths from SHOW COLUMNS except for tinyint(1), which is
        // preserved precisely because of this convention; MariaDB reports all widths.)
        // Config::set_tinyint_as_boolean(false) turns the convention off (#195).
        if ('tinyint' === $c->raw_type && 1 === $c->length && !$c->auto_increment
            && Config::instance()->get_tinyint_as_boolean()) {
            $c->type = Column::BOOLEAN;
        }

        $c->default = $c->cast($column['default'], $this);

        return $c;
    }

    /**
     * @param string $charset
     * @return void
     */
    public function set_encoding($charset)
    {
        $params = [$charset];
        $this->query('SET NAMES ?', $params);
    }

    /**
     * @return bool
     */
    public function accepts_limit_and_order_for_update_and_delete()
    {
        return true;
    }

    /**
     * A case-sensitive LIKE compares characters in a binary collation:
     * "column LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_bin". The explicit collation has the
     * strongest coercibility, so the column is converted to utf8mb4 as well. Whatever its character
     * set (latin1, utf8mb3, utf8mb4), its characters compare with the pattern's in the case given, and
     * _ is one character. CAST(? AS BINARY) compares bytes: a latin1 column never matches a non-ASCII
     * pattern, and _ is one byte of an accented letter. LIKE BINARY ? draws MySQL's deprecation
     * warning 1287.
     *
     * It assumes the connection's character set is the encoding of the data the application sends;
     * utf8mb4 is recommended. Known limit, measured on MySQL 8.4 and 9.7, not on MariaDB: a connection
     * that set_encoding() made latin1 (charset=latin1 in the connection URL, applied with SET NAMES,
     * which PDO's emulated prepares do not know of) misreads a non-ASCII pattern, latin1-encoded though
     * it is, and matches nothing (warning 1300). The same character set given to PDO itself, or
     * server-side prepares, read it right.
     *
     * A case-insensitive LIKE is the default "column LIKE ?", which the column's collation decides.
     */
    public function like_condition(string $column_sql, bool $case_sensitive, bool $not): string
    {
        if (!$case_sensitive) {
            return parent::like_condition($column_sql, false, $not);
        }

        return $this->like_column($column_sql) . ($not ? ' NOT LIKE' : ' LIKE') . ' CONVERT(? USING utf8mb4) COLLATE utf8mb4_bin';
    }

    /**
     * The operator Laravel's MySQL grammar adds, SOUNDS LIKE, which MariaDB reads too.
     *
     * @return list<string>
     */
    public function extra_operators(): array
    {
        return ['sounds like'];
    }

    /**
     * MySQL and MariaDB name the function that returns a random number RAND().
     */
    public function random_function(): string
    {
        return 'RAND()';
    }

    /**
     * @return array<string, string|array{name: string, length?: int}>
     */
    public function native_database_types()
    {
        return [
            'primary_key' => 'int(11) UNSIGNED DEFAULT NULL auto_increment PRIMARY KEY',
            'string' => ['name' => 'varchar', 'length' => 255],
            'text' => ['name' => 'text'],
            'integer' => ['name' => 'int', 'length' => 11],
            'float' => ['name' => 'float'],
            'datetime' => ['name' => 'datetime'],
            'timestamp' => ['name' => 'datetime'],
            'time' => ['name' => 'time'],
            'date' => ['name' => 'date'],
            'binary' => ['name' => 'blob'],
            'boolean' => ['name' => 'tinyint', 'length' => 1],
        ];
    }

    /**
     * MySQL/MariaDB ignore the conflict target and rely on the table's PRIMARY/
     * UNIQUE indexes. VALUES(col) is the portable form: the AS-alias syntax added
     * in MySQL 8.0.19 is not supported by MariaDB, which reuses this adapter.
     *
     * @param string[] $unique Ignored on MySQL/MariaDB
     * @param string[] $update Column names to overwrite on conflict
     * @return string
     */
    public function upsert_conflict_clause(array $unique, array $update): string
    {
        $sets = [];
        foreach ($update as $column) {
            $q = $this->quote_name($column);
            $sets[] = "$q = VALUES($q)";
        }

        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
    }
}
