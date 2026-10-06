<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Manages reading and writing to a database table.
 *
 * This class manages a database table and is used by the Model class for
 * reading and writing to its database table. There is one instance of Table
 * for every table you have a model for.
 *
 * @package ActiveRecord
 */
class Table
{
    /** @var array<string, self> */
    private static array $cache = [];

    /** @var \ReflectionClass<object> */
    public $class;
    /** @var Connection|null */
    public $conn;
    /** @var list<string> */
    public $pk;
    /** @var string|null */
    public $last_sql;

    /**
     * Name/value pairs of columns in this table
     * @var array<string, Column>
     */
    public $columns = [];

    /**
     * Name of the table.
     *
     * Always assigned by set_table_name() during construction; there is no
     * teardown path that resets it to null (unlike $conn).
     *
     * @var string
     */
    public $table;

    /**
     * Name of the database (optional)
     * @var string|null
     */
    public $db_name;

    /**
     * Name of the sequence for this table (optional). Defaults to {$table}_seq
     * @var string|null
     */
    public $sequence;

    /**
     * A instance of CallBack for this model/table.
     *
     * Always assigned at the end of the constructor; there is no teardown path
     * that resets it to null (unlike $conn).
     *
     * @static
     * @var CallBack
     */
    public $callback;

    /**
     * List of relationships for this table.
     * @var array<string, AbstractRelationship>
     */
    private array $relationships = [];

    /**
     * @param string $model_class_name
     * @return self
     */
    public static function load($model_class_name)
    {
        if (!isset(self::$cache[$model_class_name])) {
            /* do not place set_assoc in constructor..it will lead to infinite loop due to
               relationships requesting the model's table, but the cache hasn't been set yet */
            self::$cache[$model_class_name] = new Table($model_class_name);
            self::$cache[$model_class_name]->set_associations();
        }

        return self::$cache[$model_class_name];
    }

    /**
     * @param string|null $model_class_name
     * @return void
     */
    public static function clear_cache($model_class_name = null)
    {
        if ($model_class_name && array_key_exists($model_class_name, self::$cache)) {
            unset(self::$cache[$model_class_name]);
        } else {
            self::$cache = [];
        }
    }

    /**
     * @param string $class_name
     */
    public function __construct($class_name)
    {
        $this->class = Reflections::instance()->add($class_name)->get($class_name);

        $this->reestablish_connection(false);
        $this->set_table_name();
        $this->get_meta_data();
        $this->set_primary_key();
        $this->set_sequence_name();
        $this->set_delegates();
        $this->set_setters_and_getters();

        $this->callback = new CallBack($class_name);
        $this->callback->register('before_save', function (Model $model) {
            $model->set_timestamps();
        }, ['prepend' => true]);
        $this->callback->register('after_save', function (Model $model) {
            $model->reset_dirty();
        }, ['prepend' => true]);
    }

    /**
     * @param bool $close
     * @return Connection
     */
    public function reestablish_connection($close = true)
    {
        if ($close) {
            $this->drop_connection();
        }

        // if connection name property is null the connection manager will use the default connection
        $connection = $this->class->getStaticPropertyValue('connection', null);

        return ($this->conn = ConnectionManager::get_connection($connection));
    }

    /**
     * @return void
     */
    public function drop_connection()
    {
        // if connection name property is null the connection manager will use the default connection
        $connection = $this->class->getStaticPropertyValue('connection', null);

        ConnectionManager::drop_connection($connection);
        static::clear_cache();

        // Clear reference to PDO conn so that PHP will garbage collect and trigger PDO to close DB conn
        $this->conn?->close();
        $this->conn = null;
    }

    /**
     * Returns the established connection, asserting one exists.
     *
     * The connection is set at construction and only null after drop_connection()
     * (teardown); any query path requires a live connection.
     */
    private function connection(): Connection
    {
        if (null === $this->conn) {
            throw new DatabaseException('No database connection established for ' . $this->class->getName() . '; call reestablish_connection() first.');
        }

        return $this->conn;
    }

    /**
     * @param list<string>|string $joins
     * @return string
     */
    public function create_joins($joins)
    {
        if (!is_array($joins)) {
            return $joins;
        }

        $self = $this->table;
        $ret = $space = '';

        $existing_tables = [];
        foreach ($joins as $value) {
            $ret .= $space;

            if (stripos($value, 'JOIN ') === false) {
                if (array_key_exists($value, $this->relationships)) {
                    $rel = $this->get_relationship($value, true);
                    if (null === $rel) {
                        throw new RelationshipException("Relationship named $value has not been declared for class: {$this->class->getName()}");
                    }

                    // if there is more than 1 join for a given table we need to alias the table names
                    if (array_key_exists($rel->class_name, $existing_tables)) {
                        $alias = $value;
                    } else {
                        $existing_tables[$rel->class_name] = true;
                        $alias = null;
                    }

                    $ret .= $rel->construct_inner_join_sql($this, false, $alias);
                } else {
                    throw new RelationshipException("Relationship named $value has not been declared for class: {$this->class->getName()}");
                }
            } else {
                $ret .= $value;
            }

            $space = ' ';
        }
        return $ret;
    }

    /**
     * @param array<string, mixed> $options
     * @return SQLBuilder
     */
    public function options_to_sql($options)
    {
        $table = array_key_exists('from', $options) ? $options['from'] : $this->get_fully_qualified_table_name();
        $sql = new SQLBuilder($this->connection(), $table);

        if (array_key_exists('joins', $options)) {
            $sql->joins($this->create_joins($options['joins']));

            // by default, an inner join will not fetch the fields from the joined table
            if (!array_key_exists('select', $options)) {
                $options['select'] = $this->get_fully_qualified_table_name() . '.*';
            }
        }

        if (array_key_exists('select', $options)) {
            $sql->select($options['select']);
        }

        if (array_key_exists('conditions', $options)) {
            if (!is_hash($options['conditions'])) {
                if (is_string($options['conditions'])) {
                    $options['conditions'] = [$options['conditions']];
                }

                call_user_func_array([$sql,'where'], $options['conditions']);
            } else {
                $hashes = [$options['conditions']];

                if (!empty($options['mapped_names'])) {
                    $hashes = $this->map_names($options['conditions'], $options['mapped_names']);
                }

                // with `from` the query may read another table: the database decides
                if (!array_key_exists('from', $options)) {
                    foreach ($hashes as $hash) {
                        $this->validate_condition_keys($hash, is_string($options['select'] ?? null) ? $options['select'] : null);
                    }
                }

                if (1 === count($hashes)) {
                    $sql->where($hashes[0]);
                } else {
                    $sql->where_hashes($hashes);
                }
            }
        }

        if (array_key_exists('order', $options)) {
            $sql->order($options['order']);
        }

        if (array_key_exists('limit', $options)) {
            $sql->limit($options['limit']);
        }

        if (array_key_exists('offset', $options)) {
            $sql->offset($options['offset']);
        }

        if (array_key_exists('group', $options)) {
            $sql->group($options['group']);
        }

        if (array_key_exists('having', $options)) {
            $sql->having($options['having']);
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<Model>
     */
    public function find($options)
    {
        $sql = $this->options_to_sql($options);
        $readonly = (array_key_exists('readonly', $options) && $options['readonly']) ? true : false;
        $eager_load = array_key_exists('include', $options) ? $options['include'] : null;

        return $this->find_by_sql($sql->to_s(), $sql->get_where_values(), $readonly, $eager_load);
    }

    /**
     * Whether any row matches $options, without counting: emits an EXISTS query
     * that stops at the first matching row.
     *
     * @param array<string, mixed> $options
     */
    public function exists($options): bool
    {
        $conn = $this->connection();
        $sql = $this->options_to_sql($options);
        $values = $this->format_date_bind_values($sql->get_where_values());
        $this->last_sql = $conn->exists_sql($sql->to_s());

        return (bool) (int) $conn->query_and_fetch_one($this->last_sql, $values);
    }

    /**
     * @param string $sql
     * @param array<int, mixed>|null $values
     * @param bool $readonly
     * @param array<int|string, mixed>|string|null $includes
     * @return list<Model>
     */
    public function find_by_sql($sql, $values = null, $readonly = false, $includes = null)
    {
        $this->last_sql = $sql;

        $collect_attrs_for_includes = is_null($includes) ? false : true;
        $list = $attrs = [];
        $bind_values = $this->process_data($values) ?? [];
        $sth = $this->connection()->query($sql, $bind_values);

        while (($row = $sth->fetch())) {
            /** @var Model $model */
            $model = new $this->class->name($row, false, true, false);

            if ($readonly) {
                $model->readonly();
            }

            if ($collect_attrs_for_includes) {
                $attrs[] = $model->attributes();
            }

            $list[] = $model;
        }

        if ($collect_attrs_for_includes && !empty($list)) {
            $this->execute_eager_load($list, $attrs, $includes);
        }

        return $list;
    }

    /**
     * Executes an eager load of a given named relationship for this table.
     *
     * @param list<Model> $models found models for this table
     * @param list<array<string, mixed>> $attrs attrs from $models
     * @param array<int|string, mixed>|string $includes eager load directives
     * @return void
     */
    private function execute_eager_load(array $models = [], array $attrs = [], array|string $includes = [])
    {
        if (!is_array($includes)) {
            $includes = [$includes];
        }

        foreach ($includes as $index => $name) {
            // nested include
            if (is_array($name)) {
                $nested_includes = count($name) > 0 ? $name : [];
                $name = $index;
            } else {
                $nested_includes = [];
            }

            $rel = $this->get_relationship($name, true);
            if (null === $rel) {
                throw new RelationshipException("Relationship named $name has not been declared for class: {$this->class->getName()}");
            }
            $rel->load_eagerly($models, $attrs, $nested_includes, $this);
        }
    }

    /**
     * Rejects a hash-condition key that is unqualified (a plain name or one quoted
     * identifier) and names no column of this table, before any query is run. A
     * table-qualified key ('t.c', `t`.`c`) is not checked. Names are compared the
     * way the database compares them ({@see Connection::resolves_column_name()}); a
     * name missing from the cached schema is checked again against the live table,
     * so a column added after the schema was cached is not rejected.
     *
     * @internal Serves the finders, update_all/delete_all and relationship conditions.
     * @param array<int|string, mixed> $conditions A conditions hash
     * @param string|null $select The query's select list, if one was given
     * @throws DatabaseException naming the model and the key
     */
    public function validate_condition_keys(array $conditions, ?string $select = null): void
    {
        $conn = $this->connection();
        $columns = $this->column_names($this->columns);

        // no introspected column (the table could not be introspected, e.g. a pre-quoted
        // schema-qualified $table_name on Postgres): the schema is unknown, the database decides
        if ([] === $columns) {
            return;
        }

        $fresh_columns = null;

        foreach (array_keys($conditions) as $key) {
            $key = (string) $key;
            $name = $this->unqualified_condition_name($conn, $key);

            if (null === $name || $conn->resolves_column_name($name, $this->table, $columns, $select)) {
                continue;
            }

            $fresh_columns ??= $this->column_names($this->introspect_columns());

            if (!$conn->resolves_column_name($name, $this->table, $fresh_columns, $select)) {
                throw new DatabaseException("Unknown column '$key' in hash conditions for {$this->class->getName()} (table {$this->table})");
            }
        }
    }

    /**
     * A conditions hash with the model's $alias_attribute names replaced by their columns,
     * split like {@see map_names()} where an alias and its column would collide. An alias
     * named like a real column of the table is not mapped: these paths (count, exists,
     * update_all, delete_all, relationship conditions) always sent that name to the
     * database as the column, and still do.
     *
     * @internal Serves count/exists, update_all/delete_all and relationship hash conditions.
     * @param array<string, mixed> $conditions
     * @return non-empty-list<array<string, mixed>>
     */
    public function alias_condition_hashes(array $conditions): array
    {
        $map = $this->condition_alias_map();

        return [] === $map ? [$conditions] : $this->map_names($conditions, $map);
    }

    /**
     * The model's $alias_attribute map without the aliases named like a real column
     * (see {@see alias_condition_hashes()}).
     *
     * @internal
     * @return array<string, string>
     */
    public function condition_alias_map(): array
    {
        return array_filter(
            (array) $this->class->getStaticPropertyValue('alias_attribute', []),
            fn($alias) => !$this->has_column((string) $alias),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Whether this table certainly has a column named $name, compared the way its database
     * compares column names ({@see Connection::column_name_matches()}).
     *
     * @internal
     */
    public function has_column(string $name): bool
    {
        $conn = $this->connection();

        foreach (array_keys($this->columns) as $column) {
            if (true === $conn->column_name_matches($name, (string) $column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The identifier a hash-condition key is rendered as (see Expressions), or null when
     * the key is table-qualified: an unquoted dotted key, or quoted identifiers joined by '.'.
     */
    private function unqualified_condition_name(Connection $conn, string $key): ?string
    {
        $q = $conn::$QUOTE_CHARACTER;

        if (str_contains($key, '.') && !str_contains($key, $q)) {
            return null;
        }

        // quote_name() gives quoted identifiers joined by '.', each embedded quote doubled:
        // once the doubled ones are gone, any quote left marks a boundary between parts
        $inner = substr($conn->quote_name($key), 1, -1);

        return str_contains(str_replace($q . $q, '', $inner), $q) ? null : str_replace($q . $q, $q, $inner);
    }

    /**
     * @param array<int|string, Column> $columns
     * @return list<string>
     */
    private function column_names(array $columns): array
    {
        return array_map('strval', array_keys($columns));
    }

    /**
     * @param string $inflected_name
     * @return Column|null
     */
    public function get_column_by_inflected_name($inflected_name)
    {
        foreach ($this->columns as $raw_name => $column) {
            if ($column->inflected_name == $inflected_name) {
                return $column;
            }
        }
        return null;
    }

    /**
     * @param bool $quote_name
     * @return string
     */
    public function get_fully_qualified_table_name($quote_name = true)
    {
        $table = $quote_name ? $this->connection()->quote_name($this->table) : $this->table;

        if ($this->db_name) {
            $table = $this->connection()->quote_name($this->db_name) . ".$table";
        }

        return $table;
    }

    /**
     * Retrieve a relationship object for this table. Strict as true will throw an error
     * if the relationship name does not exist.
     *
     * @param int|string $name name of Relationship
     * @param bool $strict
     * @throws RelationshipException
     * @return AbstractRelationship|null
     */
    public function get_relationship($name, $strict = false)
    {
        if ($this->has_relationship($name)) {
            return $this->relationships[(string) $name];
        }

        if ($strict) {
            throw new RelationshipException("Relationship named $name has not been declared for class: {$this->class->getName()}");
        }

        return null;
    }

    /**
     * Does a given relationship exist?
     *
     * @param int|string $name name of Relationship
     * @return bool
     */
    public function has_relationship($name)
    {
        return array_key_exists($name, $this->relationships);
    }

    /**
     * @param array<string, mixed> $data
     * @param string|null $pk
     * @param string|null $sequence_name
     * @return mixed
     */
    public function insert(&$data, $pk = null, $sequence_name = null)
    {
        $data = $this->process_data($data);

        $sql = new SQLBuilder($this->connection(), $this->get_fully_qualified_table_name());
        $sql->insert($data, $pk, $sequence_name);

        $values = array_values($data);
        return $this->connection()->query(($this->last_sql = $sql->to_s()), $values);
    }

    /**
     * @param list<array<string, mixed>> $values
     * @param string|list<string> $unique_by
     * @param list<string>|null $update
     */
    public function upsert(array $values, array|string $unique_by, ?array $update = null): int
    {
        $unique_by = is_array($unique_by) ? $unique_by : [$unique_by];

        if (empty($unique_by) || in_array('', $unique_by, true)) {
            throw new ActiveRecordException('upsert requires a non-empty $unique_by.');
        }

        if (empty($values)) {
            return 0;
        }

        // Every row must share the same set of keys.
        $first_keys = array_keys(reset($values));
        $sorted = $first_keys;
        sort($sorted);
        foreach ($values as $row) {
            $keys = array_keys($row);
            sort($keys);
            if ($keys !== $sorted) {
                throw new ActiveRecordException('upsert requires every row to have the same set of keys.');
            }
        }

        // Auto-manage timestamps where the columns exist and the caller omitted them.
        $now = date('Y-m-d H:i:s');
        $has_created = isset($this->columns['created_at']);
        $has_updated = isset($this->columns['updated_at']);
        foreach ($values as &$row) {
            if ($has_created && !array_key_exists('created_at', $row)) {
                $row['created_at'] = $now;
            }
            if ($has_updated && !array_key_exists('updated_at', $row)) {
                $row['updated_at'] = $now;
            }
        }
        unset($row);

        // Canonical column order (after timestamp injection, all rows share these).
        $columns = array_keys(reset($values));

        // Resolve the update column list.
        if (is_null($update)) {
            // All inserted columns (Eloquent-faithful), minus created_at.
            $update = array_values(array_filter($columns, fn($c) => $c !== 'created_at'));
        }
        if ($update !== [] && $has_updated && !in_array('updated_at', $update, true)) {
            $update[] = 'updated_at';
        }

        // Convert values (DateTime -> string) per row.
        foreach ($values as &$row) {
            $row = $this->process_data($row);
        }
        unset($row);

        $max = $this->connection()::$MAX_BIND_PARAMS;
        $column_count = count($columns);

        if ($column_count === 0) {
            throw new ActiveRecordException('upsert requires at least one column.');
        }

        if ($column_count > $max) {
            throw new ActiveRecordException(
                "upsert: a row has more columns ($column_count) than the adapter's bind-parameter limit ($max)."
            );
        }

        // $column_count is >= 1 (checked above) and <= $max (checked above), so this is always >= 1.
        $chunk_size = max(1, intdiv($max, $column_count));
        $chunks = array_chunk($values, $chunk_size);

        $use_transaction = count($chunks) > 1 && !$this->connection()->inTransaction();
        if ($use_transaction) {
            $this->connection()->transaction();
        }

        $affected = 0;

        try {
            foreach ($chunks as $chunk) {
                $sql = new SQLBuilder($this->connection(), $this->get_fully_qualified_table_name());
                $sql->upsert($columns, count($chunk), $unique_by, $update);

                $bind = [];
                foreach ($chunk as $row) {
                    foreach ($columns as $column) {
                        $bind[] = $row[$column];
                    }
                }

                $sth = $this->connection()->query(($this->last_sql = $sql->to_s()), $bind);
                $affected += $sth->rowCount();
            }

            if ($use_transaction) {
                $this->connection()->commit();
            }
        } catch (\Throwable $e) {
            if ($use_transaction) {
                $this->connection()->rollback();
            }
            throw $e;
        }

        return $affected;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|string $where
     * @return mixed
     */
    public function update(&$data, $where)
    {
        $data = $this->process_data($data);

        // a pk hash in the WHERE is bound like the SET values (a DateTime in the column's format)
        if (is_array($where)) {
            $where = $this->process_data($where);
        }

        $sql = new SQLBuilder($this->connection(), $this->get_fully_qualified_table_name());
        $sql->update($data)->where($where);

        $values = $sql->bind_values();
        return $this->connection()->query(($this->last_sql = $sql->to_s()), $values);
    }

    /**
     * @param array<string, mixed> $data
     * @return mixed
     */
    public function delete($data)
    {
        $data = $this->process_data($data);

        $sql = new SQLBuilder($this->connection(), $this->get_fully_qualified_table_name());
        $sql->delete($data);

        $values = $sql->bind_values();
        return $this->connection()->query(($this->last_sql = $sql->to_s()), $values);
    }

    /**
     * Add a relationship.
     *
     * @param AbstractRelationship $relationship a relationship object
     * @return void
     */
    private function add_relationship(AbstractRelationship $relationship)
    {
        $this->relationships[$relationship->attribute_name] = $relationship;
    }

    /**
     * @return void
     */
    private function get_meta_data()
    {
        // as more adapters are added probably want to do this a better way
        // than using instanceof but gud enuff for now
        $quote_name = !($this->conn instanceof PgsqlAdapter);

        $table_name = $this->get_fully_qualified_table_name($quote_name);
        $conn = $this->connection();
        // a Postgres `$db` model is introspected in its schema: its own key, so a pre-quoted
        // $table_name that spells the same string ('"public".authors') never reuses it
        if ($conn instanceof PgsqlAdapter && $this->db_name) {
            $table_name .= '-in-schema';
        }

        // scoped by connection identity: same-named tables on different databases differ (#45)
        $this->columns = Cache::get('get_meta_data-' . $conn->cache_identity() . "-$table_name", function () {
            return $this->introspect_columns();
        });
    }

    /**
     * The table's columns, read from the database (no cache). On Postgres a model with
     * `$db` is looked up in that schema.
     *
     * @return array<string, Column>
     */
    private function introspect_columns(): array
    {
        $conn = $this->connection();

        if ($conn instanceof PgsqlAdapter && $this->db_name) {
            return $conn->columns_in_schema($this->table, $this->db_name);
        }

        return $conn->columns($this->get_fully_qualified_table_name(!($conn instanceof PgsqlAdapter)));
    }

    /**
     * Replaces any aliases used in a hash based condition.
     *
     * An alias and its column ('marquee' and 'name') would overwrite each other in one
     * hash, so a repeated name starts a new hash: every condition is kept, in order, and
     * the hashes are ANDed. Without a repeated name there is a single hash, as before.
     *
     * @param array<string, mixed> $hash A hash
     * @param array<string, string> $map Hash of used_name => real_name
     * @return non-empty-list<array<string, mixed>> Hashes with any aliases replaced with their real field name
     */
    private function map_names(array &$hash, array &$map): array
    {
        $hashes = [];
        $ret = [];

        foreach ($hash as $name => &$value) {
            if (array_key_exists($name, $map)) {
                $name = $map[$name];
            }

            if (array_key_exists($name, $ret)) {
                $hashes[] = $ret;
                $ret = [];
            }

            $ret[$name] = $value;
        }

        $hashes[] = $ret;

        return $hashes;
    }

    /**
     * Bind values with each \DateTimeInterface (\DateTime, \DateTimeImmutable, ActiveRecord\DateTime)
     * formatted as process_data() formats it for the finders: in the connection's datetime format,
     * or for a DATE column named by a string key, its date format. The paths that bound them raw
     * threw an Error for a native date, and bound an ActiveRecord\DateTime as its __toString()
     * text (RFC 2822 by default), which MySQL rejects and SQLite compares as text (#138).
     * Every other value is returned unchanged.
     *
     * @internal Serves exists(), Model::count(), update_all() and delete_all(); not a supported API.
     * @template TKey of int|string
     * @param array<TKey, mixed> $values
     * @return array<TKey, mixed>
     */
    public function format_date_bind_values(array $values): array
    {
        $dates = array_filter($values, fn($value) => $value instanceof \DateTimeInterface);

        return [] === $dates ? $values : array_replace($values, $this->process_data($dates));
    }

    /**
     * @template TKey of int|string
     * @param array<TKey, mixed>|null $hash
     * @return ($hash is null ? null : array<TKey, mixed>)
     */
    private function &process_data(?array $hash): ?array
    {
        if (!$hash) {
            return $hash;
        }

        foreach ($hash as $name => &$value) {
            // any DateTimeInterface: PDO cannot bind a \DateTimeImmutable either
            if ($value instanceof \DateTimeInterface) {
                if (isset($this->columns[$name]) && $this->columns[$name]->type == Column::DATE) {
                    $value = $this->connection()->date_to_string($value);
                } else {
                    $value = $this->connection()->datetime_to_string($value);
                }
            }
        }
        unset($value);

        return $hash;
    }

    private function set_primary_key(): void
    {
        if (($pk = $this->class->getStaticPropertyValue('pk', null)) || ($pk = $this->class->getStaticPropertyValue('primary_key', null))) {
            $this->pk = is_array($pk) ? array_values($pk) : [$pk];
        } else {
            $this->pk = [];

            foreach ($this->columns as $c) {
                if ($c->pk) {
                    $this->pk[] = $c->inflected_name;
                }
            }
        }
    }

    private function set_table_name(): void
    {
        if (($table = $this->class->getStaticPropertyValue('table', null)) || ($table = $this->class->getStaticPropertyValue('table_name', null))) {
            $this->table = $table;
        } else {
            // infer table name from the class name
            $this->table = Inflector::instance()->tableize($this->class->getName());

            // strip namespaces from the table name if any
            $parts = explode('\\', $this->table);
            $this->table = $parts[count($parts) - 1];
        }

        if (($db = $this->class->getStaticPropertyValue('db', null)) || ($db = $this->class->getStaticPropertyValue('db_name', null))) {
            $this->db_name = $db;
        }
    }

    private function set_sequence_name(): void
    {
        if (!$this->connection()->supports_sequences()) {
            return;
        }

        if (!($this->sequence = $this->class->getStaticPropertyValue('sequence'))) {
            // a table without a primary key has no pk column to name a sequence after
            $this->sequence = isset($this->pk[0])
                ? $this->connection()->get_sequence_name($this->table, $this->pk[0])
                : null;

            // with `$db` that derived, unqualified name would resolve through search_path,
            // possibly to a same-named table's sequence in another schema: use the
            // sequence the pk column's default really draws from (e.g. s26.books_book_id_seq)
            if ($this->db_name && isset($this->pk[0])) {
                $column = $this->columns[$this->pk[0]] ?? $this->get_column_by_inflected_name($this->pk[0]);

                if (null !== $column && $column->sequence) {
                    $this->sequence = $column->sequence;
                }
            }
        }
    }

    private function set_associations(): void
    {
        require_once 'Relationship.php';
        $namespace = $this->class->getNamespaceName();

        foreach ($this->class->getStaticProperties() as $name => $definitions) {
            if (!$definitions) {# || !is_array($definitions))
                continue;
            }

            foreach (wrap_strings_in_arrays($definitions) as $definition) {
                $relationship = null;
                $definition += compact('namespace');

                switch ($name) {
                    case 'has_many':
                        $relationship = new HasMany($definition);
                        break;

                    case 'has_one':
                        $relationship = new HasOne($definition);
                        break;

                    case 'belongs_to':
                        $relationship = new BelongsTo($definition);
                        break;

                    case 'has_and_belongs_to_many':
                        $relationship = new HasAndBelongsToMany($definition);
                        break;
                }

                if ($relationship) {
                    $this->add_relationship($relationship);
                }
            }
        }
    }

    /**
     * Rebuild the delegates array into format that we can more easily work with in Model.
     * Will end up consisting of array of:
     *
     * array('delegate' => array('field1','field2',...),
     *       'to'       => 'delegate_to_relationship',
     *       'prefix'	=> 'prefix')
     */
    private function set_delegates(): void
    {
        $delegates = $this->class->getStaticPropertyValue('delegate', []);
        $new = [];

        if (!array_key_exists('processed', $delegates)) {
            $delegates['processed'] = false;
        }

        if (!$delegates['processed']) {
            foreach ($delegates as &$delegate) {
                if (!is_array($delegate) || !isset($delegate['to'])) {
                    continue;
                }

                if (!isset($delegate['prefix'])) {
                    $delegate['prefix'] = null;
                }

                $new_delegate = [
                    'to'		=> $delegate['to'],
                    'prefix'	=> $delegate['prefix'],
                    'delegate'	=> []];

                foreach ($delegate as $name => $value) {
                    if (is_numeric($name)) {
                        $new_delegate['delegate'][] = $value;
                    }
                }

                $new[] = $new_delegate;
            }

            $new['processed'] = true;
            $this->class->setStaticPropertyValue('delegate', $new);
        }
    }

    /**
     * @deprecated Model.php now checks for get|set_ methods via method_exists so there is no need for declaring static g|setters.
     */
    private function set_setters_and_getters(): void
    {
        $getters = $this->class->getStaticPropertyValue('getters', []);
        $setters = $this->class->getStaticPropertyValue('setters', []);

        if (!empty($getters) || !empty($setters)) {
            trigger_error('static::$getters and static::$setters are deprecated. Please define your setters and getters by declaring methods in your model prefixed with get_ or set_. See
			http://www.phpactiverecord.org/projects/main/wiki/Utilities#attribute-setters and http://www.phpactiverecord.org/projects/main/wiki/Utilities#attribute-getters on how to make use of this option.', E_USER_DEPRECATED);
        }
    }
};
