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
     * Name of the sequence for this table (optional): the model's static $sequence, else
     * the sequence of the pk column (its nextval() default or its IDENTITY), else null
     * when the pk has another default (gen_random_uuid(), …), else the adapter's
     * {table}_{pk}_seq convention.
     * Only set on adapters with sequences (Postgres).
     * @var string|null
     */
    public $sequence;

    /**
     * True when an INSERT that omits the pk reads it back with RETURNING: the pk column
     * is filled by the database and the model declares no static $sequence (#47).
     */
    private bool $pk_read_back = false;

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
     * The real name of each column whose attribute (inflected) name does not name it on
     * this connection: inflected_name => name ('space_name' => 'space name'; 'some_date'
     * => 'some_Date' only where column names are case-sensitive).
     * @var array<string, string>
     */
    private array $real_column_names = [];

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
        $this->check_alias_attribute();
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
     *
     * @internal Serves the Query engine; not a supported API.
     */
    public function connection(): Connection
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
     * The SELECT the options describe, built by the Query engine. A hash of conditions has its
     * names mapped to columns through the model's $alias_attribute, unless the options give
     * the names to map as 'mapped_names'.
     *
     * @param array<string, mixed> $options
     * @return SQLBuilder
     */
    public function options_to_sql($options)
    {
        return Query::from_options($this, $options)->to_sql_builder();
    }

    /**
     * The models the options select, found by the Query engine.
     *
     * @param array<string, mixed> $options
     * @return list<Model>
     */
    public function find($options)
    {
        return Query::from_options($this, $options)->get();
    }

    /**
     * Whether any row matches $options, without counting: emits an EXISTS query
     * that stops at the first matching row, run by the Query engine.
     *
     * @param array<string, mixed> $options
     */
    public function exists($options): bool
    {
        return Query::from_options($this, $options)->exists();
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
     * The column an attribute name stands for: 'space_name' => 'space name' (#92), and on
     * a connection whose column names are case-sensitive (Postgres) 'some_date' =>
     * 'some_Date'; MySQL/SQLite match that one as is, so it is returned unchanged there.
     * A column's own name, a qualified or quoted key, an expression or anything else that
     * is not the attribute name of a differently named column is returned unchanged.
     *
     * @internal Serves the library's persistence and finder paths; not a supported API.
     */
    public function real_column_name(string $name): string
    {
        if (isset($this->columns[$name])) {
            return $name;
        }

        return $this->real_column_names[$name] ?? $name;
    }

    /**
     * A data hash (insert/SET values) with each key passed through real_column_name(),
     * in order. Two keys naming one column leave one entry, holding the later value.
     *
     * @internal Serves the library's persistence paths; not a supported API.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function real_column_keys(array $data): array
    {
        $keys = [];

        foreach ($data as $name => $value) {
            // (string): an integer key ("0") names no column and comes back unchanged
            $keys[$this->real_column_name((string) $name)] = $value;
        }

        return $keys;
    }

    /**
     * A conditions hash with its keys named as the columns (see real_column_name()), never
     * through an alias, split where a column repeats so every condition is kept: hashes to AND
     * in order. It is map_names() with no alias map.
     *
     * @internal The library no longer calls it: the Query engine maps the names of its conditions
     *   through map_names(). Kept because it is public; not a supported API.
     * @param array<string, mixed> $hash
     * @return non-empty-list<array<string, mixed>>
     */
    public function condition_hashes(array $hash): array
    {
        $map = [];

        return $this->map_names($hash, $map);
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
     * INSERTs $data, a row that does not come from a model, through the Query engine, and returns
     * the statement. The row gets the created_at and updated_at it lacks, set to the current time
     * (see Timestamps): a table without those columns gets nothing, and neither does a class whose
     * timestamps are suspended, see Model::without_timestamps(). The save() of a model does not
     * come through here: its own set_timestamps() decides its timestamps (see insert_for_save()).
     *
     * $data is taken by reference and comes back under the names it was given, its DateTime values
     * formatted for the database. The timestamps are added to the statement, not to $data.
     *
     * @param array<string, mixed> $data
     * @param string|null $pk
     * @param string|null $sequence_name
     * @return mixed
     */
    public function insert(&$data, $pk = null, $sequence_name = null)
    {
        return $this->run_insert($data, $pk, $sequence_name, null, true);
    }

    /**
     * INSERTs $data as the save() of a new record sends it: insert() without the engine's
     * timestamps. The model has set its own (set_timestamps(), which a model can override to opt
     * out), and the statement must carry exactly those.
     *
     * @internal Serves Model::insert(); not a supported API.
     * @param array<string, mixed> $data
     */
    public function insert_for_save(array &$data, ?string $pk = null, ?string $sequence_name = null): \PDOStatement
    {
        return $this->run_insert($data, $pk, $sequence_name, null, false);
    }

    /**
     * True when Model::insert() leaves an unset pk to the database and reads it back
     * with insert_returning_pk() (#47).
     *
     * @internal Serves Model::insert(); not a supported API.
     */
    public function reads_back_pk(): bool
    {
        return $this->pk_read_back;
    }

    /**
     * INSERT $data (which omits the pk) ending with RETURNING the pk, and return the pk
     * the database stored, cast by its column like find() reads it (#47); null when no
     * row was stored (a BEFORE INSERT trigger returned NULL). Like insert_for_save(), it adds
     * no timestamps of its own: it is the save() of a new record.
     *
     * @internal Serves Model::insert(); not a supported API.
     * @param array<string, mixed> $data
     */
    public function insert_returning_pk(array &$data, string $pk): mixed
    {
        $value = $this->run_insert($data, null, null, $pk, false)->fetchColumn();
        $column = $this->get_column_by_inflected_name($pk);

        // no row came back (a BEFORE INSERT trigger skipped it): the pk stays null
        if (false === $value || null === $column) {
            return false === $value ? null : $value;
        }

        return $column->cast($value, $this->connection());
    }

    /**
     * The body of insert(), whose $data keeps its untyped contract (#47): the Query engine runs
     * the statement. $timestamps is whether it fills the timestamps the row lacks (see
     * Query::execute_insert()).
     *
     * @param array<string, mixed> $data
     */
    private function run_insert(&$data, ?string $pk, ?string $sequence_name, ?string $returning, bool $timestamps): \PDOStatement
    {
        // $data keeps its attribute names for the caller (#92), its values as the statement binds them
        $data = $this->process_data($data);

        // null (no row) is an empty row, which the engine rejects as it rejects anything but a hash
        return Query::for_table($this)->execute_insert($data ?: [], $pk, $sequence_name, $returning, $timestamps);
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

        // The statement names the columns (#92): row keys, $unique_by and $update are
        // mapped from attribute names first, so everything below works on column names.
        // (string): a name that is not a string renders as quote_name() always cast it.
        $column_name = fn(mixed $name): string => $this->real_column_name((string) $name);
        $values = array_map(fn(array $row): array => $this->real_column_keys($row), $values);
        $unique_by = array_map($column_name, $unique_by);
        $update = null === $update ? null : array_map($column_name, $update);

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
     * UPDATEs the rows of $where with $data through the Query engine, and returns the statement,
     * which is left in $last_sql. It is the UPDATE of a save(), whose model sets its own timestamps
     * (Model::set_timestamps()): the engine adds none, also when it is called directly.
     *
     * $data is taken by reference and comes back under the names it was given, its DateTime values
     * formatted for the database. Its keys and those of a $where hash name the columns (#92), as
     * attribute or column names, never through an alias: a pk is not aliased, also where an alias is
     * named like a column that the check at load did not see (defense in depth, spec §6.2.6). A
     * DateTime of the $where hash is bound in the format of its column, and two of its keys naming
     * one column both stay conditions. Anything else as $where is the one argument of a condition,
     * as before.
     *
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

        $conditions = is_array($where) && is_hash($where) ? $where : [$where];

        // the engine sends the values as they are prepared here. Null (nothing to write) is an empty
        // set, which it rejects as it rejects anything but a hash. 'mapped_names' => []: a pk hash reads
        // no alias. The check at load (check_alias_attribute()) keeps an alias from being named like a
        // column, but a shadow it did not see must not move the rows a save() writes
        return Query::from_options($this, ['conditions' => $conditions, 'mapped_names' => []])
            ->execute_update($data ?: [], timestamps: false, format_dates: false);
    }

    /**
     * DELETEs the rows of $data, a pk hash, through the Query engine, and returns the statement,
     * which is left in $last_sql: the DELETE of Model::delete().
     *
     * The keys of $data name the columns (#92), as attribute or column names, never through an
     * alias: a pk is not aliased, also where an alias is named like a column that the check at load
     * did not see (defense in depth, spec §6.2.6). A DateTime of it is bound in the format of its
     * column, and two of its keys naming one column both stay conditions. Anything but a hash is the
     * one argument of a condition, as before.
     *
     * @param array<string, mixed> $data
     * @return mixed
     */
    public function delete($data)
    {
        $data = $this->process_data($data);
        $conditions = is_hash($data) ? $data : [$data];

        // the engine sends the values as they are prepared here. 'mapped_names' => []: a pk hash reads no
        // alias (see update())
        return Query::from_options($this, ['conditions' => $conditions, 'mapped_names' => []])->execute_delete(format_dates: false);
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
        // scoped by connection identity: same-named tables on different databases differ (#45)
        $this->columns = Cache::get('get_meta_data-' . $conn->cache_identity() . "-$table_name", function () use ($conn, $table_name) {
            return $conn->columns($table_name);
        });

        // A name differing only by case (some_Date) is mapped only where column names are
        // case-sensitive (Postgres): MySQL/SQLite already match it, and their SQL stays as it
        // was. Two columns with one inflected name: the first wins, as in
        // get_column_by_inflected_name().
        $case_sensitive = $conn->column_names_are_case_sensitive();

        foreach ($this->columns as $name => $column) {
            if ($column->inflected_name !== $name && ($case_sensitive || strtolower($name) !== $column->inflected_name)) {
                $this->real_column_names[$column->inflected_name] ??= $name;
            }
        }
    }

    /**
     * Rejects a model whose $alias_attribute has a key named like a column of this table, by the
     * column's real name or by its attribute name (#92), or like a pseudo-column, one every table has
     * though its schema never lists it (see Connection::pseudo_columns()), so that a name in a hash
     * condition is an alias or a column whatever the schema holds when the query runs (#144, spec
     * §6.2.6). Names are compared as the database compares column names (see
     * Connection::column_name_key()): regardless of case, except on Postgres. An alias key is read as
     * a hash condition reads a key (see Connection::column_reference_key()): a single pre-quoted
     * identifier by its text.
     *
     * It reads the columns as get_meta_data() found them. A shadowing column that is not among them,
     * one added to the table after it loaded (a long-running process, a stale metadata cache) or a
     * SQLite generated column, which PRAGMA table_info omits, leaves the alias winning in every hash
     * condition, a pk hash excepted (the library never reads one through an alias): the next load
     * with fresh metadata reports it.
     *
     * @throws ConfigException
     */
    private function check_alias_attribute(): void
    {
        /** @var class-string<Model> $model_class */
        $model_class = $this->class->name;
        $aliases = $model_class::$alias_attribute;

        if ([] === $aliases) {
            return;
        }

        $conn = $this->connection();
        $real_names = [];

        // by the key the database knows each name by; a name two columns have stays the first one's
        foreach ($this->columns as $column) {
            $real_names[$conn->column_name_key($column->name)] ??= $column->name;
            $real_names[$conn->column_name_key($column->inflected_name)] ??= $column->name;
        }

        // a hash names a pseudo-column as it names the table's own columns: with joins it gets the table too
        // (spec §6.1)
        foreach ($conn->pseudo_columns() as $name) {
            $real_names[$conn->column_name_key($name)] ??= $name;
        }

        foreach (array_keys($aliases) as $alias) {
            $real_name = $real_names[$conn->column_reference_key((string) $alias)] ?? null;

            if (null !== $real_name) {
                throw new ConfigException(sprintf(
                    "%s::\$alias_attribute: the alias '%s' has the same name as the column '%s' of table %s; rename the alias",
                    $model_class,
                    $alias,
                    $real_name,
                    $this->table,
                ));
            }
        }
    }

    /**
     * Replaces any aliases used in a hash based condition, then names each attribute
     * as its column (see real_column_name(), #92).
     *
     * An alias and its column ('marquee' and 'name'), or an attribute and its column
     * ('some_date' and 'some_Date'), would overwrite each other in one hash, so a
     * repeated name starts a new hash: every condition is kept, in order, and the hashes
     * are ANDed. Without a repeated name there is a single hash, as before.
     *
     * @internal Serves the Query engine and the declared conditions of relationships
     *   (AbstractRelationship::to_positional_conditions()); not a supported API.
     * @param array<string, mixed> $hash A hash
     * @param array<string, mixed> $map Hash of used_name => real_name (a model's $alias_attribute, as configured)
     * @return non-empty-list<array<string, mixed>> Hashes with any aliases replaced with their real field name
     */
    public function map_names(array &$hash, array &$map): array
    {
        $hashes = [];
        $ret = [];

        foreach ($hash as $name => &$value) {
            if (array_key_exists($name, $map)) {
                $name = $map[$name];
            }

            // a misconfigured alias target that is not a string stays as it was
            $name = is_string($name) ? $this->real_column_name($name) : $name;

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
     * @internal Serves the Query engine; not a supported API.
     * @template TKey of int|string
     * @param array<TKey, mixed>|null $hash
     * @return ($hash is null ? null : array<TKey, mixed>)
     */
    public function &process_data(?array $hash): ?array
    {
        if (!$hash) {
            return $hash;
        }

        foreach ($hash as $name => &$value) {
            // any DateTimeInterface: PDO cannot bind a \DateTimeImmutable either
            if ($value instanceof \DateTimeInterface) {
                // a string key names a column or an attribute ('some_date' for some_Date, #92)
                $column = is_string($name) ? ($this->columns[$this->real_column_name($name)] ?? null) : null;

                if (null !== $column && $column->type == Column::DATE) {
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
            if (!isset($this->pk[0])) {
                $this->sequence = null;
                return;
            }

            $column = $this->get_column_by_inflected_name($this->pk[0]);

            if (null !== $column && $column->filled_by_database) {
                // the database fills the pk (any sequence name, an IDENTITY, a uuid default…):
                // name its real sequence, if any, and read the pk back on insert (#47, #153)
                $this->sequence = $column->sequence;
                $this->pk_read_back = $this->connection()->supports_insert_returning();
            } else {
                $this->sequence = $this->connection()->get_sequence_name($this->table, $this->pk[0]);
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
