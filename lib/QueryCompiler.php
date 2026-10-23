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
 * @phpstan-import-type Where from QueryState
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
     *
     * @throws ActiveRecordException for a state that holds legacy options and builder clauses both: the
     *   one would be compiled and the other dropped
     */
    public function compile_select(QueryState $state): SQLBuilder
    {
        if (null === $state->legacy) {
            return $this->compile_clauses($state);
        }

        if (self::holds_clauses($state)) {
            throw new ActiveRecordException(sprintf('A query on %s holds both legacy options and builder clauses: it compiles the one or the other', $this->table->class->name));
        }

        return $this->compile_legacy($state->legacy);
    }

    /**
     * A table reference of the builder, as from() takes it, and the tables of joins: the table $table
     * under the alias $as, or when $as is null under the alias that "table as alias" gives it (see
     * split_alias()); given an alias, the table is taken as written. It returns the table and the
     * alias; the reference as SQL, the table quoted part by part, every part since a table has no *
     * form, then " AS " and the alias as one identifier; and the name the reference gives its rows, as
     * SQL: the alias, else the table.
     *
     * @internal Serves Query; not a supported API.
     * @param string $method The builder method the reference was given to, for the message of the exception
     * @return array{table: string, alias: string|null, sql: string, name: string}
     * @throws ActiveRecordException for an empty table name or alias
     */
    public function table_reference(string $table, ?string $as, string $method): array
    {
        if (null === $as) {
            $input = $table;
            [$table, $as] = $this->split_alias($table);
            $this->refuse_empty('table name', $table, $input, $method);
            $this->refuse_empty('alias', $as, $input, $method);
        } else {
            $this->refuse_empty('table name', $table, $table, $method);
            $this->refuse_empty('alias', $as, $as, $method);
        }

        $sql = $this->quote_parts($table, false);

        if (null === $as) {
            return ['table' => $table, 'alias' => null, 'sql' => $sql, 'name' => $sql];
        }

        $alias = $this->connection()->quote_name($as);

        return ['table' => $table, 'alias' => $as, 'sql' => "$sql AS $alias", 'name' => $alias];
    }

    /**
     * A column as select() takes it: the name, and the alias that "name as alias" gives it, if any (see
     * split_alias()). The name is read by column() when the statement is compiled.
     *
     * @internal Serves Query; not a supported API.
     * @param string $method The builder method the column was given to, for the message of the exception
     * @return array{column: string, alias: string|null}
     * @throws ActiveRecordException for an empty column name or alias
     */
    public function aliased_column(string $name, string $method): array
    {
        [$column, $alias] = $this->split_alias($name);
        $this->refuse_empty('column name', $column, $name, $method);
        $this->refuse_empty('alias', $alias, $name, $method);

        return ['column' => $column, 'alias' => $alias];
    }

    /**
     * A column as a where clause of the builder takes it: the name, read by column() when the
     * statement is compiled.
     *
     * @internal Serves Query; not a supported API.
     * @param string $method The builder method the name was given to, for the message of the exception
     * @throws ActiveRecordException for an empty name
     */
    public function column_name(string $name, string $method): string
    {
        $this->refuse_empty('column name', $name, $name, $method);

        return $name;
    }

    /**
     * A condition written in SQL and the values of its bind markers, read as a legacy positional
     * condition is (SQLBuilder::where()): a value that is a list is spread over its marker by
     * Expressions, IN(?) with [1, 2] being IN(?,?) and with [] IN(NULL). Without one, both are
     * returned as given.
     *
     * @internal Serves Query; not a supported API.
     * @param list<mixed> $values
     * @return array{string, list<mixed>}
     * @throws ExpressionsException for more bind markers than values, when one is a list
     */
    public function raw_condition(string $sql, array $values): array
    {
        if ([] === array_filter($values, is_array(...))) {
            return [$sql, $values];
        }

        require_once __DIR__ . '/Expressions.php';
        $expressions = new Expressions($this->connection(), $sql);
        $expressions->bind_values($values);

        return [$expressions->to_s(), array_flatten($expressions->values())];
    }

    /**
     * The WHERE of the builder's conditions (Query::where() and its family), without the keyword,
     * and the values of its bind markers in order; null when it has none. With $conditions, the
     * engine's own conditions (the primary key of a find, ...), each one condition with its values,
     * they come first, ANDed, and the builder's WHERE follows in parentheses: an OR in it never
     * widens them (spec §5.2), as the legacy finders keep their pk apart from the conditions they
     * are given (#149).
     *
     * @internal Serves Query; not a supported API.
     * @param list<array{sql: string, values: list<mixed>}> $conditions
     * @return array{sql: string, values: list<mixed>}|null
     */
    public function where_clause(QueryState $state, array $conditions = []): ?array
    {
        $own = [] === $state->wheres ? null : $this->wheres_sql($state->wheres, $state);

        if ([] === $conditions) {
            return $own;
        }

        $sql = [];
        $values = [];

        foreach ($conditions as $condition) {
            $sql[] = $condition['sql'];
            $values = [...$values, ...$condition['values']];
        }

        if (null !== $own) {
            $sql[] = "({$own['sql']})";
            $values = [...$values, ...$own['values']];
        }

        return ['sql' => implode(' AND ', $sql), 'values' => $values];
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
        return $this->column_reference($name, $state)['sql'];
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
     * table or the FROM that from() or from_raw() gives, with the joins and the WHERE.
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
        $sql->select(($state->distinct ? 'DISTINCT ' : '') . $columns);
        $sql->select_values($values);

        $where = $this->where_clause($state);

        if (null !== $where) {
            $sql->where_condition($where['sql'], $where['values']);
        }

        return $sql;
    }

    /**
     * The conditions of one level of the builder's WHERE, each joined to the one before it by its AND
     * or OR (that of the first is dropped), and their values in order.
     *
     * @param list<Where> $wheres
     * @return array{sql: string, values: list<mixed>}
     */
    private function wheres_sql(array $wheres, QueryState $state): array
    {
        $sql = '';
        $values = [];
        // SQL written by hand goes in parentheses beside another condition, which its OR could escape into
        $beside = count($wheres) > 1;

        foreach ($wheres as $i => $where) {
            [$condition, $condition_values] = $this->where_sql($where, $state, $beside);
            $sql .= (0 === $i ? '' : " {$where['boolean']} ") . $condition;
            $values = [...$values, ...$condition_values];
        }

        return ['sql' => $sql, 'values' => $values];
    }

    /**
     * One condition of the builder's WHERE (see QueryState::$wheres) and its values, each prepared for
     * the column it is compared with (see value()). Its columns are named by column(). $beside: another
     * condition shares its level.
     *
     * @param Where $where
     * @return array{string, list<mixed>}
     */
    private function where_sql(array $where, QueryState $state, bool $beside): array
    {
        return match ($where['type']) {
            'basic' => $this->comparison_sql($where['column'], $where['operator'], $where['value'], $state),
            'column' => [$this->column($where['first'], $state) . " {$where['operator']} " . $this->column($where['second'], $state), []],
            'raw' => [$beside ? "({$where['sql']})" : $where['sql'], $where['bindings']],
            'in' => $this->in_sql($where['column'], $where['values'], $where['not'], $state),
            'null' => [$this->column($where['column'], $state) . ($where['not'] ? ' IS NOT NULL' : ' IS NULL'), []],
            'between' => $this->between_sql($where['column'], $where['values'][0], $where['values'][1], $where['not'], $state),
            'between_columns' => [
                $this->column($where['column'], $state) . ($where['not'] ? ' NOT BETWEEN ' : ' BETWEEN ')
                    . $this->column($where['columns'][0], $state) . ' AND ' . $this->column($where['columns'][1], $state),
                [],
            ],
            'like' => [
                $this->connection()->like_condition($this->column($where['column'], $state), $where['case_sensitive'], $where['not']),
                [$this->connection()->like_pattern($where['value'], $where['case_sensitive'])],
            ],
            'hash' => $this->hash_sql($where['column'], $where['value'], $state),
            'group' => $this->group_sql($where['group'], $where['not'], $state),
        };
    }

    /**
     * A column compared with a value by an operator, or with null: IS NULL by = and <=>, IS NOT NULL by
     * != and <> (Query refuses any other operator with null).
     *
     * @return array{string, list<mixed>}
     */
    private function comparison_sql(string $name, string $operator, mixed $value, QueryState $state): array
    {
        $column = $this->column_reference($name, $state);

        if (null === $value) {
            return [$column['sql'] . (in_array($operator, ['!=', '<>'], true) ? ' IS NOT NULL' : ' IS NULL'), []];
        }

        return ["{$column['sql']} $operator ?", [$this->value($column['column'], $value)]];
    }

    /**
     * A column IN a list of values, or with $not NOT IN, by the rules of a hash of conditions (spec §5.2):
     * an empty list matches no row (1=0), or with $not every row (1=1); a null in the list is compared
     * with IS NULL ORed, or IS NOT NULL ANDed, and nulls only are that comparison alone.
     *
     * @param list<mixed> $values
     * @return array{string, list<mixed>}
     */
    private function in_sql(string $name, array $values, bool $not, QueryState $state): array
    {
        if ([] === $values) {
            return [$not ? '1=1' : '1=0', []];
        }

        $column = $this->column_reference($name, $state);
        $listed = array_values(array_filter($values, fn(mixed $value): bool => null !== $value));
        $null = $column['sql'] . ($not ? ' IS NOT NULL' : ' IS NULL');

        if ([] === $listed) {
            return [$null, []];
        }

        $in = $column['sql'] . ($not ? ' NOT IN(' : ' IN(') . implode(',', array_fill(0, count($listed), '?')) . ')';
        $prepared = array_map(fn(mixed $value): mixed => $this->value($column['column'], $value), $listed);

        if (count($listed) === count($values)) {
            return [$in, $prepared];
        }

        return [$not ? "($in AND $null)" : "($in OR $null)", $prepared];
    }

    /**
     * A column BETWEEN two values, or with $not NOT BETWEEN.
     *
     * @return array{string, list<mixed>}
     */
    private function between_sql(string $name, mixed $low, mixed $high, bool $not, QueryState $state): array
    {
        $column = $this->column_reference($name, $state);

        return [
            $column['sql'] . ($not ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?'),
            [$this->value($column['column'], $low), $this->value($column['column'], $high)],
        ];
    }

    /**
     * A column => value entry of a hash of conditions, rendered by Expressions as an entry of a legacy
     * conditions hash is (spec §5.2): a list is IN, an empty one 1=0, a null in it IS NULL ORed, and
     * a null IS NULL. The column is named by column(), and its values prepared for it.
     *
     * @return array{string, list<mixed>}
     */
    private function hash_sql(string $name, mixed $value, QueryState $state): array
    {
        require_once __DIR__ . '/Expressions.php';
        $column = $this->column_reference($name, $state);
        $value = is_array($value)
            ? array_map(fn(mixed $element): mixed => $this->value($column['column'], $element), $value)
            : $this->value($column['column'], $value);
        $expressions = new Expressions($this->connection(), [$column['sql'] => $value]);

        return [$expressions->to_s(), array_flatten($expressions->values())];
    }

    /**
     * A group of conditions in parentheses, or with $not its negation, NOT (...).
     *
     * @return array{string, list<mixed>}
     */
    private function group_sql(QueryState $group, bool $not, QueryState $state): array
    {
        $wheres = $this->wheres_sql($group->wheres, $state);

        return [($not ? 'NOT (' : '(') . $wheres['sql'] . ')', $wheres['values']];
    }

    /**
     * A value compared with a column of the builder, as the database receives it: prepared by
     * Table::process_data() for $column, so that a DateTime takes the format of the column's type, a
     * date for a DATE column (spec §5.2). A name that is no column of the model gets the format of a
     * positional value, a date and time.
     */
    private function value(string $column, mixed $value): mixed
    {
        return $this->table->process_data([$column => $value])[$column];
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
     * The FROM of the builder: from_raw()'s as written, else the table reference of from() (see
     * table_reference()), else the model's table.
     */
    private function from_clause(QueryState $state): string
    {
        if (null !== $state->from_raw) {
            return $state->from_raw;
        }

        if (null === $state->from) {
            return $this->table->get_fully_qualified_table_name();
        }

        return $this->table_reference($state->from, $state->from_alias, 'from')['sql'];
    }

    /**
     * The name the statement reads the model's rows under, which qualifies their columns with joins:
     * the from() alias, else the table from() reads them from, which stands for the model's (see
     * table_reference()), else the model's table, under a raw FROM too (the legacy 'from' option
     * selects its rows by it as well).
     */
    private function rows_name(QueryState $state): string
    {
        if (null !== $state->from_raw || null === $state->from) {
            return $this->table->get_fully_qualified_table_name();
        }

        return $this->table_reference($state->from, $state->from_alias, 'from')['name'];
    }

    /**
     * Whether a state holds a clause of the builder: a field, other than the legacy options, that is
     * not as in a new state.
     */
    private static function holds_clauses(QueryState $state): bool
    {
        $legacy = ['legacy' => true];

        return array_diff_key(get_object_vars($state), $legacy) !== array_diff_key(get_object_vars(new QueryState()), $legacy);
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
     * column()'s reading of $name: the SQL that names it, and the name its values are prepared by (see
     * value()). That is the column an unqualified name stands for, the text of a pre-quoted one included;
     * any other name is taken as written, and names no column of the model.
     *
     * @return array{sql: string, column: string}
     */
    private function column_reference(string $name, QueryState $state): array
    {
        $parts = $this->name_parts($name);

        // table.column, table.* and *, or what is no name of identifiers: as written
        if (null === $parts || 1 !== count($parts) || '*' === $name) {
            return ['sql' => $this->quote_parts($name), 'column' => $name];
        }

        // an alias names an attribute, and an attribute its column; a pre-quoted name is the column's own
        $connection = $this->connection();
        $quoted = $parts[0][1];
        $column = $quoted ? $name : $this->table->real_column_name($this->alias_attributes()[$name] ?? $name);
        $sql = $connection->quote_name($column);
        // the text of a pre-quoted identifier, in which a doubled quote character is one
        $q = $connection::$QUOTE_CHARACTER;
        $data = $quoted ? str_replace($q . $q, $q, substr($name, 1, -1)) : $column;

        if ([] === $state->joins || null !== $state->from_raw || !$this->names_model_column($column)) {
            return ['sql' => $sql, 'column' => $data];
        }

        return ['sql' => $this->rows_name($state) . '.' . $sql, 'column' => $data];
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
     * A name quoted part by part (#64), each part by Connection::quote_name(), which keeps a part already
     * quoted as it is. With $keep_star, as in a column reference, a last part * stays bare (table.*); a
     * table has no such form. What is no dotted name of identifiers, with an empty part or a quote left
     * open, is quoted as one identifier.
     */
    private function quote_parts(string $name, bool $keep_star = true): string
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
            $quoted[] = $keep_star && $last === $i && '*' === $part ? $part : $connection->quote_name($part);
        }

        return implode('.', $quoted);
    }

    /**
     * A name and the alias that "name as alias" gives it, or the name and null. AS is read in any case,
     * with whitespace on each side ("canvas" holds none), where it first stands outside a quoted
     * identifier, in which a doubled quote character is a quote (`x as y` is one name): as in
     * Eloquent, but for the quotes.
     *
     * @return array{string, string|null}
     */
    private function split_alias(string $name): array
    {
        $connection = $this->connection();
        $q = preg_quote($connection::$QUOTE_CHARACTER, '/');
        // what comes before AS: characters other than the quote, and whole quoted identifiers. A doubled
        // quote reads as one identifier closed and the next opened, with nothing between where AS could
        // stand, so it needs no case of its own
        $before = "(?:[^{$q}]|{$q}[^{$q}]*{$q})*?";

        return 1 === preg_match("/\\A($before)\\s+as\\s+(.*)\\z/is", $name, $matches) ? [$matches[1], $matches[2]] : [$name, null];
    }

    /**
     * Refuses an empty $what (a column name, a table name, an alias): '' or only whitespace. The
     * message names $method, the model and $input, the argument it was read from. Null is no alias,
     * which is not refused.
     *
     * @throws ActiveRecordException
     */
    private function refuse_empty(string $what, ?string $value, string $input, string $method): void
    {
        if (null !== $value && '' === trim($value)) {
            throw new ActiveRecordException(sprintf("Empty %s: '%s' in %s() on %s", $what, $input, $method, $this->table->class->name));
        }
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
