<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Turns a QueryState into the SQLBuilder that renders it: the one place that does.
 *
 * @internal Serves the Query engine; not a supported API.
 * @package ActiveRecord
 */
final class QueryCompiler
{
    /**
     * The keys (see Connection::column_name_key()) of the names of the model's columns (see
     * model_column_names()), read once.
     *
     * @var array<string, true>|null
     */
    private ?array $model_column_keys = null;

    /**
     * The statements are rendered for $connection when one is given, else for $table's: a bulk write
     * of Model gives the connection it always built its statement on (see Query::execute_update()).
     */
    public function __construct(private readonly Table $table, private readonly ?Connection $connection = null) {}

    /**
     * The SELECT of a state: that of its legacy options when it holds some, as the legacy entries always
     * sent it, else that of the builder's clauses. A state that holds nothing selects every row.
     */
    public function compile_select(QueryState $state): SQLBuilder
    {
        return null === $state->legacy ? $this->compile_clauses($state) : $this->compile_legacy($state->legacy);
    }

    /**
     * How a clause of the builder names a column: the one rule every clause follows (spec §5.1).
     *
     * - An unqualified name of a column of the model, by its alias, its attribute name or its own name,
     *   is the column's real name (#92, #144), quoted. With joins it also gets the name the statement
     *   reads the model's rows under (the from() alias, else the from() table, else the model's table;
     *   see rows_name()), so that a joined table's column of the same name never makes it ambiguous.
     *   Under a raw FROM (from_raw()), which names its rows itself, nothing is qualified.
     * - Any other unqualified name, a joined table's column or the alias of an expression, is quoted as
     *   written and never qualified.
     * - A qualified name (table.column, table.*) and * are quoted part by part as written (#64), a part
     *   already quoted kept as it is.
     *
     * A name is the model's as the database matches column names, as a key of a hash of conditions
     * with joins is (see SQLBuilder::qualify_hash_keys()): regardless of case except on Postgres, and
     * a pre-quoted name by its text, which no alias or attribute name maps. The columns every table has
     * without listing them (rowid, ...) are the model's too. The result is only ever identifiers, never
     * SQL: an expression is written with the *_raw() methods.
     */
    public function column(string $name, QueryState $state): string
    {
        $parts = $this->name_parts($name);

        // table.column, table.* and *, or what is no name of identifiers: as written
        if (null === $parts || 1 !== count($parts) || '*' === $name) {
            return $this->quote_parts($name);
        }

        // an alias names an attribute, and an attribute its column; a pre-quoted name is the column's own
        $column = $parts[0][1] ? $name : $this->table->real_column_name($this->alias_attributes()[$name] ?? $name);
        $sql = $this->connection()->quote_name($column);

        if ([] === $state->joins || null !== $state->from_raw || !$this->names_model_column($column)) {
            return $sql;
        }

        return $this->rows_name($state) . '.' . $sql;
    }

    /**
     * The UPDATE of a state. $set is a hash whose keys are the columns' real names and whose values
     * are prepared (see Query::execute_update()), or a raw SQL string; SQLBuilder rejects anything
     * else. The WHERE, ORDER BY and LIMIT are the state's (see apply_write_clauses()).
     *
     * @param array<string, mixed>|string $set
     */
    public function compile_update(QueryState $state, array|string $set): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->update($set);
        $this->apply_write_clauses($sql, $state->legacy ?? []);

        return $sql;
    }

    /**
     * The DELETE of a state, whose WHERE, ORDER BY and LIMIT are the state's (see
     * apply_write_clauses()). A state without conditions deletes every row.
     */
    public function compile_delete(QueryState $state): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->delete();
        $this->apply_write_clauses($sql, $state->legacy ?? []);

        return $sql;
    }

    /**
     * The INSERT of one row: the SQLBuilder calls Table::run_insert() made before the engine took
     * it over, moved as they were, so that it sends the same SQL. The row is a hash whose keys
     * are the columns' real names and whose values are prepared (see Query::execute_insert()).
     * $pk, an attribute or column name, is the column $sequence feeds, and $returning, another,
     * the one the statement returns (#47). SQLBuilder rejects what is no hash.
     *
     * @param array<string, mixed> $row
     */
    public function compile_insert(array $row, ?string $pk = null, ?string $sequence = null, ?string $returning = null): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->insert($row, null === $pk ? $pk : $this->table->real_column_name($pk), $sequence);

        if (null !== $returning) {
            $sql->returning($this->table->real_column_name($returning));
        }

        return $sql;
    }

    /**
     * The UPSERT of $row_count rows of $columns: the SQLBuilder calls Table::upsert() made before the
     * engine took it over, moved as they were. The names are the columns' real names, unquoted (see
     * Query::upsert()). $unique_by is the conflict target where the adapter names one, and $update the
     * columns a conflict overwrites: with none the statement is a plain INSERT, without a conflict clause.
     *
     * @param list<string> $columns
     * @param list<string> $unique_by
     * @param list<string> $update
     */
    public function compile_upsert(array $columns, int $row_count, array $unique_by, array $update): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->upsert($columns, $row_count, $unique_by, $update);

        return $sql;
    }

    /**
     * The SELECT a legacy options array describes: the body Table::options_to_sql() had before
     * the engine took it over, moved as it was (its calls into Table go through $this->table), so
     * that every legacy entry sends the same SQL and the same bind values. Joins come before
     * conditions, so the keys of a hash that name the model's columns get its table (#35); any
     * other key, a joined table's, stays as written. A hash's names are read through the
     * model's aliases (#144), unless the options give the names to map as 'mapped_names'.
     *
     * @param array<string, mixed> $options
     */
    private function compile_legacy(array $options): SQLBuilder
    {
        $table = array_key_exists('from', $options) ? $options['from'] : $this->table->get_fully_qualified_table_name();
        $sql = new SQLBuilder($this->connection(), $table);

        if (array_key_exists('joins', $options)) {
            $sql->joins($this->table->create_joins($options['joins']));

            // a 'from' replaces the model's table by another, whose columns are not known here:
            // every key keeps naming it, as it always did. Otherwise the columns of the model
            // name it, and so do the ones every table has but its schema never lists (rowid, ...)
            if (!array_key_exists('from', $options)) {
                $sql->qualify_hash_keys($this->model_column_names());
            }

            // by default, an inner join will not fetch the fields from the joined table
            if (!array_key_exists('select', $options)) {
                $options['select'] = $this->default_select(true, $this->table->get_fully_qualified_table_name());
            }
        }

        if (array_key_exists('select', $options)) {
            $sql->select($options['select']);
        }

        $this->apply_conditions($sql, $options);

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
     * The SELECT of the builder's clauses: what it selects, else the default select, from the model's
     * table or the FROM that from() or from_raw() gives, with the joins.
     */
    private function compile_clauses(QueryState $state): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->from_clause($state));
        $sql->from_values($state->from_bindings);

        if ([] !== $state->joins) {
            $sql->joins(implode(' ', $state->joins));
        }

        $columns = [];
        $values = [];

        foreach ($state->selects as $select) {
            if (array_key_exists('raw', $select)) {
                $columns[] = $select['raw'];
                $values = [...$values, ...$select['bindings']];
            } else {
                $alias = null === $select['alias'] ? '' : ' AS ' . $this->connection()->quote_name($select['alias']);
                $columns[] = $this->column($select['column'], $state) . $alias;
            }
        }

        $columns = [] === $columns ? $this->default_select([] !== $state->joins, $this->rows_name($state)) : implode(', ', $columns);
        $sql->select(($state->distinct ? 'DISTINCT ' : '') . $columns, $values);

        return $sql;
    }

    /**
     * What a SELECT that names no columns selects, for the legacy options and the builder alike: every
     * column, or with joins every column of the model's rows only, which the statement reads under
     * $rows. A joined table's columns would otherwise come too, and overwrite the model's attributes of
     * the same name.
     */
    private function default_select(bool $joins, string $rows): string
    {
        return $joins ? "$rows.*" : '*';
    }

    /**
     * The FROM of the builder: from_raw()'s as written, else the table of from(), quoted part by part,
     * with its alias, else the model's table.
     */
    private function from_clause(QueryState $state): string
    {
        if (null !== $state->from_raw) {
            return $state->from_raw;
        }

        if (null === $state->from) {
            return $this->table->get_fully_qualified_table_name();
        }

        $table = $this->quote_parts($state->from);

        return null === $state->from_alias ? $table : "$table AS " . $this->connection()->quote_name($state->from_alias);
    }

    /**
     * The name the statement reads the model's rows under, which qualifies their columns with joins:
     * the from() alias, else the table from() reads them from, which stands for the model's, else the
     * model's table, under a raw FROM too (the legacy 'from' option selects its rows by it as well).
     */
    private function rows_name(QueryState $state): string
    {
        if (null !== $state->from_raw || null === $state->from) {
            return $this->table->get_fully_qualified_table_name();
        }

        return null === $state->from_alias ? $this->quote_parts($state->from) : $this->connection()->quote_name($state->from_alias);
    }

    /**
     * The WHERE of the 'conditions' of legacy options, if they have any: a string; a list, a
     * condition and its values as SQLBuilder::where() takes them; or a hash, whose names are read
     * through the model's aliases (#144), unless the options give the names to map as
     * 'mapped_names' (an empty list maps no alias), and are then named as the columns (#92).
     *
     * @param array<string, mixed> $options
     */
    private function apply_conditions(SQLBuilder $sql, array $options): void
    {
        if (!array_key_exists('conditions', $options)) {
            return;
        }

        if (!is_hash($options['conditions'])) {
            if (is_string($options['conditions'])) {
                $options['conditions'] = [$options['conditions']];
            }

            call_user_func_array([$sql,'where'], $options['conditions']);
        } else {
            // aliases and attribute names become column names (#92): the model's aliases,
            // unless the options give the names to map (an empty list maps no alias)
            $mapped_names = $options['mapped_names'] ?? $this->alias_attributes();
            $hashes = $this->table->map_names($options['conditions'], $mapped_names);

            if (1 === count($hashes)) {
                $sql->where($hashes[0]);
            } else {
                $sql->where_hashes($hashes);
            }
        }
    }

    /**
     * The clauses of a legacy UPDATE or DELETE: its conditions, read as a SELECT reads them (see
     * apply_conditions()), its limit and its order, the only options a write reads. Where the
     * adapter has no LIMIT on UPDATE and DELETE (Postgres), a limit picks the rows by the columns of
     * the primary key in a subquery (#92), or by the adapter's row identity without one.
     *
     * @param array<string, mixed> $options
     */
    private function apply_write_clauses(SQLBuilder $sql, array $options): void
    {
        $this->apply_conditions($sql, $options);

        if (isset($options['limit'])) {
            $sql->limit($options['limit']);
        }

        if (isset($options['order'])) {
            $sql->order($options['order']);
        }

        $sql->key_columns(array_map($this->table->real_column_name(...), $this->table->pk));
    }

    /**
     * The connection the statements are rendered for (see the constructor).
     */
    private function connection(): Connection
    {
        return $this->connection ?? $this->table->connection();
    }

    /**
     * The names of the model's columns: those of its table, and the pseudo-columns of the connection
     * (rowid, ...), which every table has though its schema never lists them. With joins, they are
     * the keys of a hash of conditions that get the model's table, and the names that a clause of the
     * builder qualifies (see column()).
     *
     * @return list<string>
     */
    private function model_column_names(): array
    {
        return [...array_keys($this->table->columns), ...$this->connection()->pseudo_columns()];
    }

    /**
     * Whether $reference names one of the model's columns (see model_column_names()), as the database
     * matches names (see Connection::column_reference_key()).
     */
    private function names_model_column(string $reference): bool
    {
        $connection = $this->connection();
        $this->model_column_keys ??= array_fill_keys(array_map($connection->column_name_key(...), $this->model_column_names()), true);

        return isset($this->model_column_keys[$connection->column_reference_key($reference)]);
    }

    /**
     * The parts of a dotted name, in the quote character of the connection (see
     * Connection::split_name_parts()), or null when $name is not one.
     *
     * @return list<array{0: string, 1: bool}>|null
     */
    private function name_parts(string $name): ?array
    {
        $connection = $this->connection();

        return Connection::split_name_parts($name, $connection::$QUOTE_CHARACTER);
    }

    /**
     * A name quoted part by part (#64): each part quoted by Connection::quote_name(), which keeps a part
     * already quoted as it is, and a last part * left bare (table.*). What is no dotted name of
     * identifiers, with an empty part or a quote left open, is quoted as one identifier.
     */
    private function quote_parts(string $name): string
    {
        $connection = $this->connection();
        $parts = $this->name_parts($name);

        if (null === $parts) {
            return $connection->quote_name($name);
        }

        $last = count($parts) - 1;
        $quoted = [];

        // a quoted part is held with its quotes: a quoted * is a column named *
        foreach ($parts as $i => [$part]) {
            $quoted[] = $last === $i && '*' === $part ? $part : $connection->quote_name($part);
        }

        return implode('.', $quoted);
    }

    /**
     * The model's $alias_attribute: the names a hash of conditions can use for its columns.
     *
     * @return array<string, string>
     */
    private function alias_attributes(): array
    {
        /** @var class-string<Model> $model_class */
        $model_class = $this->table->class->name;

        return $model_class::$alias_attribute;
    }
}
