<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Adapter for Postgres (not completed yet)
 *
 * @package ActiveRecord
 */
class PgsqlAdapter extends Connection
{
    public static $QUOTE_CHARACTER = '"';
    public static $DEFAULT_PORT = 5432;
    /**
     * @var int
     */
    public static $MAX_BIND_PARAMS = 65535;

    public function supports_sequences()
    {
        return true;
    }

    /**
     * @internal Serves Table's pk read-back; not a supported API.
     */
    public function supports_insert_returning(): bool
    {
        return true;
    }

    /**
     * A quoted identifier keeps its case: "some_Date" is not some_date (#92).
     *
     * @internal Serves Table's attribute-to-column mapping; not a supported API.
     */
    public function column_names_are_case_sensitive(): bool
    {
        return true;
    }

    /**
     * Postgres rejects a value that is not an integer when it compares it with an INTEGER
     * column (22P02, which also aborts the open transaction): a string that is not an
     * integer literal (#92). A float is judged by the string it is sent as: 2.0 goes as
     * '2', but 1.5, INF and 1e15 (sent as '1.0E+15') are rejected. NAN and INF are
     * rejected before the cast, which warns for NAN on PHP 8.5.
     *
     * @internal Serves Model::find_by_pk(); not a supported API.
     */
    public function accepts_integer_value(mixed $value): bool
    {
        return match (true) {
            is_string($value) => 1 === preg_match('/\A\s*[+-]?\d+\s*\z/', $value),
            is_float($value) => is_finite($value) && $this->accepts_integer_value((string) $value),
            default => true,
        };
    }

    /**
     * Postgres has no LIMIT/ORDER BY on UPDATE and DELETE, so the rows are picked by key
     * in a subquery, as Rails does (#92): "id" IN (SELECT "id" FROM t WHERE ... ORDER BY
     * ... LIMIT n). A table without a primary key is keyed by its row identity
     * (tableoid, ctid): ctid alone repeats across the partitions of a partitioned table.
     *
     * @internal Serves SQLBuilder; not a supported API.
     * @param list<string> $key
     */
    public function limited_rows_condition(string $table, ?string $where, ?string $order, int $limit, array $key): ?string
    {
        $select = [] === $key ? 'tableoid, ctid' : implode(', ', $key);
        $columns = 1 === count($key) ? $select : "($select)";
        $sql = "SELECT $select FROM $table";

        if (null !== $where) {
            $sql .= " WHERE $where";
        }

        if (null !== $order) {
            $sql .= " ORDER BY $order";
        }

        return "$columns IN ($sql LIMIT $limit)";
    }

    public function get_sequence_name($table, $column_name)
    {
        return "{$table}_{$column_name}_seq";
    }

    public function next_sequence_value($sequence_name)
    {
        // a standard string literal: a quote is doubled (standard_conforming_strings is on by default)
        return "nextval('" . str_replace("'", "''", $sequence_name) . "')";
    }

    public function limit($sql, $offset, $limit)
    {
        return $sql . ' LIMIT ' . intval($limit) . ' OFFSET ' . intval($offset);
    }

    public function offset_without_limit(string $sql, int $offset): string
    {
        return "$sql OFFSET $offset";
    }

    public function exists_sql(string $inner): string
    {
        // Postgres EXISTS() returns a boolean (t/f); cast so the scalar is 1/0.
        return "SELECT EXISTS($inner)::int";
    }

    public function query_column_info($table)
    {
        $sql = <<<SQL
            SELECT
                  a.attname AS field,
                  a.attlen,
                  REPLACE(pg_catalog.format_type(a.atttypid, a.atttypmod), 'character varying', 'varchar') AS type,
                  a.attnotnull AS not_nullable,
                  (SELECT 't'
                    FROM pg_index
                    WHERE c.oid = pg_index.indrelid
                    AND a.attnum = ANY (pg_index.indkey)
                    AND pg_index.indisprimary = 't'
                  ) IS NOT NULL AS pk,      
                  REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE((SELECT pg_get_expr(pg_attrdef.adbin, pg_attrdef.adrelid)
                    FROM pg_attrdef
                    WHERE c.oid = pg_attrdef.adrelid
                    AND pg_attrdef.adnum=a.attnum
                  ),'::[a-z_ ]+',''),'''$',''),'^''','') AS default,
                  a.attidentity,
                  CASE WHEN a.attidentity <> '' THEN pg_get_serial_sequence(c.oid::regclass::text, a.attname)::regclass::text END AS identity_sequence
            FROM pg_attribute a, pg_class c, pg_type t
            WHERE c.relname = ?
                  AND a.attnum > 0
                  AND a.attrelid = c.oid
                  AND a.atttypid = t.oid
            ORDER BY a.attnum
            SQL;
        $values = [$table];
        return $this->query($sql, $values);
    }

    public function query_for_tables()
    {
        return $this->query("SELECT tablename FROM pg_tables WHERE schemaname NOT IN('information_schema','pg_catalog')");
    }

    /**
     * @param array<string, mixed> $column
     *   One row from query_column_info(): field/type/default are text, but
     *   not_nullable/pk/attlen come back through the pgsql driver's text
     *   protocol, so their PHP type (string vs bool/int) isn't guaranteed.
     * @return Column
     */
    public function create_column(&$column)
    {
        $c = new Column();
        $c->inflected_name	= Inflector::instance()->variablize($column['field']);
        $c->name			= $column['field'];
        $c->nullable		= ($column['not_nullable'] ? false : true);
        $c->pk				= ($column['pk'] ? true : false);
        $c->auto_increment	= false;

        if (substr($column['type'], 0, 9) == 'timestamp') {
            $c->raw_type = 'datetime';
            $c->length = 19;
        } elseif ($column['type'] == 'date') {
            $c->raw_type = 'date';
            $c->length = 10;
        } else {
            preg_match('/^([A-Za-z0-9_]+)(\(([0-9]+(,[0-9]+)?)\))?/', $column['type'], $matches);

            $c->raw_type = (count($matches) > 0 ? $matches[1] : $column['type']);
            $c->length = count($matches) >= 4 ? intval($matches[3]) : intval($column['attlen']);

            if ($c->length < 0) {
                $c->length = null;
            }
        }

        $c->map_raw_type();

        if ($column['default']) {
            preg_match("/^nextval\('(.*)'\)$/", $column['default'], $matches);

            if (count($matches) == 2) {
                $c->sequence = $matches[1];
            } else {
                $c->default = $c->cast($column['default'], $this);
            }
        }

        // an IDENTITY has no default expression: its sequence comes from the catalog (#47).
        // A row from an overriding query_column_info() may lack the keys.
        if ('' !== ($column['attidentity'] ?? '')) {
            $c->sequence = $column['identity_sequence'] ?? null;
        }

        // a sequence default or an IDENTITY: the database fills the column an INSERT omits
        $c->filled_by_database = null !== $c->sequence;
        return $c;
    }

    /**
     * @param string $charset
     * @return void
     */
    public function set_encoding($charset)
    {
        $this->query("SET NAMES '$charset'");
    }

    /**
     * @return array<string, string|array{name: string, length?: int}>
     */
    public function native_database_types()
    {
        return [
            'primary_key' => 'serial primary key',
            'string' => ['name' => 'character varying', 'length' => 255],
            'text' => ['name' => 'text'],
            'integer' => ['name' => 'integer'],
            'float' => ['name' => 'float'],
            'datetime' => ['name' => 'datetime'],
            'timestamp' => ['name' => 'timestamp'],
            'time' => ['name' => 'time'],
            'date' => ['name' => 'date'],
            'binary' => ['name' => 'binary'],
            'boolean' => ['name' => 'boolean'],
        ];
    }

}
