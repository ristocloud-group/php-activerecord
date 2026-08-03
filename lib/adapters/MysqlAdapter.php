<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Adapter for MySQL.
 *
 * @package ActiveRecord
 */
class MysqlAdapter extends Connection
{
    public static $DEFAULT_PORT = 3306;

    public static $MAX_BIND_PARAMS = 65535;

    public static $datetime_format = 'Y-m-d H:i:s';

    public function limit($sql, $offset, $limit)
    {
        $offset = is_null($offset) ? '' : intval($offset) . ',';
        $limit = intval($limit);
        return "$sql LIMIT {$offset}$limit";
    }

    public function query_column_info($table)
    {
        return $this->query("SHOW COLUMNS FROM $table");
    }

    public function query_for_tables()
    {
        return $this->query('SHOW TABLES');
    }

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
        $c->default = $c->cast($column['default'], $this);

        return $c;
    }

    public function set_encoding($charset)
    {
        $params = [$charset];
        $this->query('SET NAMES ?', $params);
    }

    public function accepts_limit_and_order_for_update_and_delete()
    {
        return true;
    }

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
