<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

use PDO;

/**
 * Adapter for SQLite.
 *
 * @package ActiveRecord
 * @phpstan-import-type ConnectionInfo from Connection
 */
class SqliteAdapter extends Connection
{
    public static $datetime_format = 'Y-m-d H:i:s';

    // SQLITE_MAX_VARIABLE_NUMBER is 999 before SQLite 3.32.0 and 32766 after,
    // and is not readily queryable via PDO — use the safe lower bound.
    /**
     * @var int
     */
    public static $MAX_BIND_PARAMS = 999;

    /**
     * @param ConnectionInfo $info Parsed connection-url object (see Connection::parse_connection_url())
     */
    protected function __construct($info)
    {
        if (!file_exists($info->host)) {
            throw new DatabaseException("Could not find sqlite db: $info->host");
        }

        $this->connection = new PDO("sqlite:$info->host", null, null, static::$PDO_OPTIONS);
    }

    /**
     * Bind positional params by PHP type so numeric predicates work under
     * SQLite's dynamic typing. The default (PARAM_STR for everything) makes
     * `length(col) = 14` bind `'14'`; SQLite compares INTEGER vs TEXT across
     * storage classes and never matches. MySQL/Postgres coerce and are handled
     * by the base implementation — this override is SQLite-only.
     *
     * Only a 0-indexed list of positional (`?`) params is type-bound; a
     * non-list (named/associative `:name` params) falls back to the base
     * execute() so PDO binds by key, preserving prior behavior.
     *
     * Floats have no dedicated PDO param type; PARAM_STR is correct (SQLite
     * applies REAL affinity to numeric text in arithmetic contexts).
     *
     * Bools become ints here too (see Connection::bind_values) so boolean
     * columns store the same 0/1 integers as the other adapters.
     *
     * @param array<int, mixed> $values Positional bind values
     */
    protected function bind_values(\PDOStatement $sth, array $values): bool
    {
        foreach ($values as &$v) {
            if (is_bool($v)) {
                $v = (int) $v;
            }
        }
        unset($v);

        if (!array_is_list($values)) {
            return $sth->execute($values);
        }

        foreach ($values as $i => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                null === $value => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $sth->bindValue($i + 1, $value, $type);
        }

        return $sth->execute();
    }

    public function limit($sql, $offset, $limit)
    {
        $offset = is_null($offset) ? '' : intval($offset) . ',';
        $limit = intval($limit);
        return "$sql LIMIT {$offset}$limit";
    }

    public function query_column_info($table)
    {
        return $this->query("pragma table_info($table)");
    }

    public function query_for_tables()
    {
        return $this->query("SELECT name FROM sqlite_master");
    }

    /**
     * @param array{cid: int, name: string, type: string, notnull: int, dflt_value: string|null, pk: int} $column
     *   One row from `pragma table_info($table)`.
     * @return Column
     */
    public function create_column($column)
    {
        $c = new Column();
        $c->inflected_name  = Inflector::instance()->variablize($column['name']);
        $c->name            = $column['name'];
        $c->nullable        = $column['notnull'] ? false : true;
        $c->pk              = $column['pk'] ? true : false;
        $c->auto_increment  = in_array(
            strtoupper($column['type']),
            ['INT', 'INTEGER']
        ) && $c->pk;

        $column['type'] = preg_replace('/ +/', ' ', $column['type']) ?? $column['type'];
        $column['type'] = str_replace(['(',')'], ' ', $column['type']);
        $column['type'] = Utils::squeeze(' ', $column['type']);
        $matches = explode(' ', $column['type']);

        $c->raw_type = strtolower($matches[0]);

        if (count($matches) > 1) {
            $c->length = intval($matches[1]);
        }

        $c->map_raw_type();

        if ($c->type == Column::DATETIME) {
            $c->length = 19;
        } elseif ($c->type == Column::DATE) {
            $c->length = 10;
        }

        // From SQLite3 docs: The value is a signed integer, stored in 1, 2, 3, 4, 6,
        // or 8 bytes depending on the magnitude of the value.
        // so is it ok to assume it's possible an int can always go up to 8 bytes?
        if ($c->type == Column::INTEGER && !$c->length) {
            $c->length = 8;
        }

        $c->default = $c->cast($column['dflt_value'], $this);

        return $c;
    }

    /**
     * @param string $charset
     * @return void
     */
    public function set_encoding($charset)
    {
        throw new ActiveRecordException("SqliteAdapter::set_charset not supported.");
    }

    /**
     * @return bool
     */
    public function accepts_limit_and_order_for_update_and_delete()
    {
        return true;
    }

    /**
     * @return array<string, string|array{name: string, length?: int}>
     */
    public function native_database_types()
    {
        return [
            'primary_key' => 'integer not null primary key',
            'string' => ['name' => 'varchar', 'length' => 255],
            'text' => ['name' => 'text'],
            'integer' => ['name' => 'integer'],
            'float' => ['name' => 'float'],
            'decimal' => ['name' => 'decimal'],
            'datetime' => ['name' => 'datetime'],
            'timestamp' => ['name' => 'datetime'],
            'time' => ['name' => 'time'],
            'date' => ['name' => 'date'],
            'binary' => ['name' => 'blob'],
            'boolean' => ['name' => 'boolean'],
        ];
    }

}
