<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * One query on one model class, and the engine that turns it into SQL.
 *
 * It holds a QueryState, which QueryCompiler renders as a SQLBuilder, and runs that statement
 * through its terminals (get, exists, ...). The legacy entries (find, count, exists, ...) hand it
 * their options array through from_options(). The writes of Table (insert, update, delete) and of
 * Model (update_all, delete_all) run through its execute_*() methods, and the upsert of Table through
 * upsert().
 *
 * @template TModel of Model
 * @package ActiveRecord
 */
class Query
{
    private QueryState $state;

    private ?Table $table = null;

    /**
     * @param class-string<TModel> $model_class
     */
    public function __construct(private readonly string $model_class)
    {
        $this->state = new QueryState();
    }

    /**
     * A query on $table's model that holds a legacy options array exactly as given: the options
     * of a finder (those Table::options_to_sql() receives), or the conditions, order and limit of
     * a legacy write, which execute_update() and execute_delete() read. Model::update_all() and
     * Model::delete_all() give those of their options; Table::update() and Table::delete() give
     * their pk hash as the conditions, with 'mapped_names' => [] so that no alias is read.
     *
     * Like for_table(), it reads and builds through $table itself.
     *
     * @internal Serves the legacy entries; not a supported API.
     * @param array<string, mixed> $options
     * @return self<Model>
     */
    public static function from_options(Table $table, array $options): self
    {
        $query = self::for_table($table);
        $query->state->legacy = $options;

        return $query;
    }

    /**
     * A query on $table's model that holds no options: where a statement starts that reads none,
     * such as the INSERT of Table::insert() (see execute_insert()) and the UPSERT of Table::upsert()
     * (see upsert()). It reads and builds through $table itself, not through the Table the model
     * class would load, which can be another instance.
     *
     * @internal Serves the legacy entries; not a supported API.
     * @return self<Model>
     */
    public static function for_table(Table $table): self
    {
        /** @var class-string<Model> $model_class */
        $model_class = $table->class->name;

        $query = new self($model_class);
        $query->table = $table;

        return $query;
    }

    /**
     * The SELECT this query describes, as a new SQLBuilder each time.
     *
     * @internal Serves the legacy entries; not a supported API.
     */
    public function to_sql_builder(): SQLBuilder
    {
        return (new QueryCompiler($this->table()))->compile_select($this->state);
    }

    /**
     * @internal Serves the legacy entries; not a supported API.
     */
    public function state(): QueryState
    {
        return $this->state;
    }

    /**
     * Runs the query and returns the models of the rows it selects, through Table::find_by_sql().
     * The legacy options 'readonly' (the models are readonly) and 'include' (the relationships to
     * eager load) apply.
     *
     * @return list<TModel>
     */
    public function get(): array
    {
        $options = $this->state->legacy ?? [];
        $sql = $this->to_sql_builder();
        $readonly = array_key_exists('readonly', $options) && $options['readonly'];
        $includes = array_key_exists('include', $options) ? $options['include'] : null;

        /** @var list<TModel> $models */
        $models = $this->table()->find_by_sql($sql->to_s(), $sql->get_where_values(), $readonly, $includes);

        return $models;
    }

    /**
     * Whether any row matches, without counting: the adapter's EXISTS() over the SELECT, which stops
     * at the first row. The statement that ran is left in Table::$last_sql.
     */
    public function exists(): bool
    {
        $table = $this->table();
        $conn = $table->connection();
        $sql = $this->to_sql_builder();
        $values = $table->format_date_bind_values($sql->get_where_values());
        $statement = $conn->exists_sql($sql->to_s());
        $table->last_sql = $statement;

        return (bool) (int) $conn->query_and_fetch_one($statement, $values);
    }

    /**
     * The execution of Model::count(), moved here: the SELECT of the legacy options is run (count()
     * has them select COUNT(*)) and the first column of its first row is returned, 0 when it
     * returns none (#34). A grouped count is that of the first group. Unlike exists(), it leaves
     * Table::$last_sql alone, as count() always did.
     *
     * It runs on $connection when one is given: Model::count() passes static::connection(), a
     * public static that a subclass can override, so that count() keeps calling that hook as it
     * always did. Without one it runs on the Table's connection.
     *
     * @internal Serves Model::count(); not a supported API.
     */
    public function legacy_count(?Connection $connection = null): int|string
    {
        $table = $this->table();
        $sql = $this->to_sql_builder();
        $values = $table->format_date_bind_values($sql->get_where_values());
        $row = ($connection ?? $table->connection())->query($sql->to_s(), $values)->fetch(\PDO::FETCH_NUM);

        return false === $row ? 0 : $row[0];
    }

    /**
     * INSERTs one row into the model's table and returns the statement that ran: the body of
     * Table::run_insert(), moved here. The row's keys are column names or attribute names (#92) and
     * its values are prepared as the finders prepare theirs (Table::process_data(): a DateTime in
     * the format of its column). $pk and $sequence are the pk column that the sequence feeds, and
     * $returning the one the statement returns (RETURNING, #47). The statement is left in
     * Table::$last_sql.
     *
     * With $timestamps, the row gets the created_at and updated_at it lacks, the way Timestamps
     * fills them for the class the table was loaded for (what Model::without_timestamps() of it
     * suspends). Without it the row is sent as it is: the INSERT of a model's save(), whose own
     * set_timestamps() decides the timestamps, so that a model that overrides it keeps saving
     * without them.
     *
     * Anything but a hash (an empty row, a list) gets no timestamps and goes to SQLBuilder, which
     * rejects it: they would otherwise make a row of an empty one.
     *
     * @internal Serves Table's inserts; not a supported API.
     * @param array<string, mixed> $row
     */
    public function execute_insert(array $row, ?string $pk = null, ?string $sequence = null, ?string $returning = null, bool $timestamps = true): \PDOStatement
    {
        $table = $this->table();

        // the statement names the columns (#92) and binds each value as the finders do, a DateTime in
        // the format of its column: what Table::process_data() prepares
        $row = $table->real_column_keys($table->process_data($row));

        if ($timestamps && is_hash($row)) {
            $row = Timestamps::apply_to_insert($table, $table->class->getName(), $row);
        }

        $sql = (new QueryCompiler($table))->compile_insert($row, $pk, $sequence, $returning);
        $values = array_values($row);

        return $table->connection()->query(($table->last_sql = $sql->to_s()), $values);
    }

    /**
     * UPDATEs the rows of the query's conditions, order and limit (the legacy options it holds, see
     * from_options()) and returns the statement that ran, left in Table::$last_sql. Where the
     * adapter has no LIMIT on UPDATE (Postgres), a limit picks the rows by primary key in a
     * subquery (#92).
     *
     * $set is a hash or a raw SQL string. The keys of a hash are column names or attribute names
     * (#92), and it gets the updated_at it lacks, the way Timestamps fills it for the class the
     * table was loaded for (what Model::without_timestamps() of it suspends), unless $timestamps is
     * false: the UPDATE of a save(), whose model sets its own timestamps (set_timestamps()). A string
     * is sent as written, without timestamps: a column added to it could be assigned twice, which
     * Postgres refuses (spec §5.6). Anything else (an empty hash, a list) gets no timestamps and goes
     * to SQLBuilder, which rejects it ("Updating requires a hash or string."): the timestamps would
     * otherwise make a SET of an empty one.
     *
     * The values are prepared: a DateTime of the hash in the format of its column (#138), and one
     * among the bind values of the conditions as the finders bind a positional value, unless
     * $format_dates is false. Table::update() turns it off: it formats its data and its pk hash
     * itself, by column (Table::process_data()), and its statement always sent them as they are.
     *
     * The statement is rendered for $connection and runs on it when one is given: Model::update_all()
     * passes static::connection(), a public static that a subclass can override, on which it always
     * built and ran its statement. Without one, the Table's.
     *
     * @internal Serves Model::update_all() and Table::update(); not a supported API.
     * @param array<string, mixed>|string $set
     */
    public function execute_update(array|string $set, bool $timestamps = true, bool $format_dates = true, ?Connection $connection = null): \PDOStatement
    {
        $table = $this->table();

        if (is_array($set)) {
            // the statement names the columns (#92)
            $set = $table->real_column_keys($set);

            if ($format_dates) {
                $set = $table->format_date_bind_values($set);
            }

            if ($timestamps && is_hash($set)) {
                $set = Timestamps::apply_to_update($table, $table->class->getName(), $set);
            }
        }

        $sql = (new QueryCompiler($table, $connection))->compile_update($this->state, $set);

        return $this->execute_write($sql, $format_dates, $connection);
    }

    /**
     * DELETEs the rows of the query's conditions, order and limit (the legacy options it holds, see
     * from_options()) and returns the statement that ran, left in Table::$last_sql. A query without
     * conditions deletes every row. Where the adapter has no LIMIT on DELETE (Postgres), a limit
     * picks the rows by primary key in a subquery (#92).
     *
     * A DateTime among the bind values is bound as the finders bind a positional value (#138),
     * unless $format_dates is false: Table::delete() formats its pk hash itself, by column
     * (Table::process_data()), and its statement always sent it as it is.
     *
     * The statement is rendered for $connection and runs on it when one is given: Model::delete_all()
     * passes static::connection(), as execute_update() describes. Without one, the Table's.
     *
     * @internal Serves Model::delete_all() and Table::delete(); not a supported API.
     */
    public function execute_delete(bool $format_dates = true, ?Connection $connection = null): \PDOStatement
    {
        $sql = (new QueryCompiler($this->table(), $connection))->compile_delete($this->state);

        return $this->execute_write($sql, $format_dates, $connection);
    }

    /**
     * Bulk insert-or-update: the body of Table::upsert(), moved here. One atomic operation that bypasses
     * validations, callbacks and dirty-tracking, and returns the rows it affected, summed across its
     * statements (MySQL counts a row it updated twice).
     *
     * $values is a list of rows, hashes that all have the same keys. These and the names of $unique_by
     * and $update are column names or attribute names (#92). $unique_by identifies a record: the
     * conflict target where the adapter names one (Postgres, SQLite); MySQL conflicts on any unique
     * index. $update is the columns a conflict overwrites: null, every column the rows insert except
     * created_at; an empty list, none, which makes a plain INSERT that fails on a duplicate. The
     * values are prepared as the finders prepare theirs (Table::process_data()), once. The rows go in
     * statements of at most Connection::$MAX_BIND_PARAMS binds, in one transaction when there are
     * several and the connection is not in one already.
     *
     * The timestamps are those of Timestamps, for the class the table was loaded for: every row gets
     * the created_at and updated_at it lacks, all set to the one time of the call, and updated_at
     * is overwritten on a conflict besides, unless $update is empty. While the class is suspended
     * (Model::without_timestamps()) the rows go as they are and updated_at is not added to the
     * columns a conflict overwrites: a timestamp the caller puts in a row, or in $update, is the
     * caller's.
     *
     * It runs on the connection of the Table, as Table::upsert() always did, and never calls
     * Model::connection(), the hook a subclass can override and update_all() and delete_all() take
     * theirs from. The last statement it sent is left in Table::$last_sql. The query's own conditions
     * play no part: an upsert has none.
     *
     * @param list<array<string, mixed>> $values
     * @param string|list<string> $unique_by
     * @param list<string>|null $update
     * @throws ActiveRecordException before any SQL: for an empty $unique_by, rows that do not share their
     *   keys, no column to insert, or a row with more columns than the adapter has bind parameters
     */
    public function upsert(array $values, array|string $unique_by, ?array $update = null): int
    {
        $table = $this->table();
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
        $column_name = fn(mixed $name): string => $table->real_column_name((string) $name);
        $values = array_map(fn(array $row): array => $table->real_column_keys($row), $values);
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

        // The timestamps the rows lack, all at the one time of the call (see Timestamps)
        $class = $table->class->getName();
        $now = Timestamps::now();
        $values = array_map(fn(array $row): array => Timestamps::apply_to_insert($table, $class, $row, $now), $values);

        // Canonical column order (after timestamp injection, all rows share these).
        $columns = array_keys(reset($values));

        // Resolve the update column list.
        $timestamps = Timestamps::columns($table);
        if (is_null($update)) {
            // All inserted columns (Eloquent-faithful), minus created_at.
            $update = array_values(array_filter($columns, fn($c) => $c !== $timestamps['created_at']));
        }
        // updated_at is overwritten on a conflict as an UPDATE sets it, unless the class is suspended
        $stamps_updated_at = null !== $timestamps['updated_at'] && !Timestamps::suspended($class);
        $conflict_lacks_updated_at = $update !== [] && !in_array($timestamps['updated_at'], $update, true);
        if ($stamps_updated_at && $conflict_lacks_updated_at) {
            $update[] = $timestamps['updated_at'];
        }

        // Convert values (DateTime -> string) per row.
        $values = array_map(fn(array $row): array => $table->process_data($row), $values);

        $connection = $table->connection();
        $max = $connection::$MAX_BIND_PARAMS;
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

        $use_transaction = count($chunks) > 1 && !$connection->inTransaction();
        if ($use_transaction) {
            $connection->transaction();
        }

        $compiler = new QueryCompiler($table);
        $affected = 0;

        try {
            foreach ($chunks as $chunk) {
                $sql = $compiler->compile_upsert($columns, count($chunk), $unique_by, $update);

                $bind = [];
                foreach ($chunk as $row) {
                    foreach ($columns as $column) {
                        $bind[] = $row[$column];
                    }
                }

                $sth = $connection->query(($table->last_sql = $sql->to_s()), $bind);
                $affected += $sth->rowCount();
            }

            if ($use_transaction) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if ($use_transaction) {
                $connection->rollback();
            }
            throw $e;
        }

        return $affected;
    }

    /**
     * Runs an UPDATE or a DELETE on $connection, else on the Table's, and leaves it in
     * Table::$last_sql. With $format_dates, a DateTime among its bind values is formatted as a
     * positional value is (Table::format_date_bind_values()).
     */
    private function execute_write(SQLBuilder $sql, bool $format_dates, ?Connection $connection): \PDOStatement
    {
        $table = $this->table();
        $values = $format_dates ? $table->format_date_bind_values($sql->bind_values()) : $sql->bind_values();

        return ($connection ?? $table->connection())->query(($table->last_sql = $sql->to_s()), $values);
    }

    private function table(): Table
    {
        return $this->table ??= Table::load($this->model_class);
    }
}
