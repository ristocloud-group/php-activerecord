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

    public function get_sequence_name($table, $column_name)
    {
        return "{$table}_{$column_name}_seq";
    }

    public function next_sequence_value($sequence_name)
    {
        return "nextval('" . str_replace("'", "\\'", $sequence_name) . "')";
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

    /**
     * @param string $table
     * @param string|null $schema Restrict the lookup to this schema (see {@see columns_in_schema()})
     */
    public function query_column_info($table, ?string $schema = null)
    {
        $in_schema = null === $schema ? '' : ' AND c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = ?)';

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
                  ),'::[a-z_ ]+',''),'''$',''),'^''','') AS default
            FROM pg_attribute a, pg_class c, pg_type t
            WHERE c.relname = ?{$in_schema}
                  AND a.attnum > 0
                  AND a.attrelid = c.oid
                  AND a.atttypid = t.oid
            ORDER BY a.attnum
            SQL;
        $values = [$table];

        if (null !== $schema) {
            $values[] = $schema;
        }

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
        return $c;
    }

    /**
     * The columns of the table $table in the schema $schema, both unquoted names (a
     * quoted one is unquoted first): what a model with `$db` introspects.
     *
     * @internal Serves Table; not a supported API.
     * @return array<string, Column>
     */
    public function columns_in_schema(string $table, string $schema): array
    {
        $unquote = fn(string $name) => 1 === preg_match('/\A"((?:[^"]|"")+)"\z/', $name, $m) ? str_replace('""', '"', $m[1]) : $name;
        $columns = [];
        $sth = $this->query_column_info($unquote($table), $unquote($schema));

        while (($row = $sth->fetch())) {
            $c = $this->create_column($row);
            $columns[$c->name] = $c;
        }

        return $columns;
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
     * A quoted identifier is case-sensitive, so names compare exactly. Postgres also
     * provides the system columns and the table name itself (a whole-row reference),
     * and truncates a name longer than 63 bytes, which is then left to the database.
     *
     * @internal
     * @param list<string> $columns
     */
    public function resolves_column_name(string $name, string $table, array $columns, ?string $select = null): bool
    {
        return parent::resolves_column_name($name, $table, $columns, $select)
            || in_array($name, ['ctid', 'xmin', 'xmax', 'cmin', 'cmax', 'tableoid', 'oid', $table], true)
            || strlen($name) > 63;
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
