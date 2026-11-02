<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Reads the arguments of a condition of the query builder (Query::where() and its family) into the plain
 * shapes of QueryState::$wheres, which ConditionCompiler renders. What it cannot read it refuses at the call
 * (spec §5.7), naming the method, the model and the input: an unknown operator, a null that the operator
 * cannot compare, an array where a value goes, an empty column name, and so on. The conditions of a join and
 * of a HAVING are read the same way.
 *
 * @internal Serves Query; not a supported API.
 * @package ActiveRecord
 * @phpstan-import-type Where from QueryState
 * @phpstan-import-type WhereBasic from QueryState
 * @phpstan-import-type WhereColumn from QueryState
 * @phpstan-import-type WhereRaw from QueryState
 * @phpstan-import-type WhereIn from QueryState
 * @phpstan-import-type WhereNull from QueryState
 * @phpstan-import-type WhereBetween from QueryState
 * @phpstan-import-type WhereBetweenColumns from QueryState
 * @phpstan-import-type WhereLike from QueryState
 * @phpstan-import-type WhereHash from QueryState
 */
final class Conditions
{
    /**
     * The operators a condition takes: Laravel 13's, in lower case, and those the adapter adds (see
     * Connection::extra_operators()). They are matched in any case and written in upper case; any
     * other is refused (spec §5.2), where Laravel takes it for the value.
     *
     * @var list<string>
     */
    private const array OPERATORS = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>', 'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>', '&~', 'is', 'is not', 'rlike', 'not rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to', 'not similar to', 'not ilike', '~~*', '!~~*',
    ];

    /**
     * The operators that compare with null: = and <=> as IS NULL, != and <> as IS NOT NULL.
     *
     * @var list<string>
     */
    private const array NULL_OPERATORS = ['=', '<=>', '!=', '<>'];

    /**
     * @param Table $table The table of the model the conditions are on
     * @param string $model_class The model, as the messages of the exceptions name it
     */
    public function __construct(private readonly Table $table, private readonly string $model_class) {}

    /**
     * A comparison of a column with a value (see Query::where()). $count is the number of the arguments
     * given after the column: one is the value, compared with =; two are an operator, where null is =,
     * and the value.
     *
     * @param 'AND'|'OR' $boolean How the condition joins those before it
     * @return WhereBasic
     * @throws ActiveRecordException for an empty column name, no value, an unknown operator, a null
     *   compared by another operator than =, !=, <> and <=>, or an array as the value
     */
    public function comparison(string $method, string $column, mixed $operator, mixed $value, int $count, string $boolean): array
    {
        $column = $this->column_name($method, $column);
        [$operator, $value] = $this->operator_and_value($method, $column, $operator, $value, $count);

        return ['type' => 'basic', 'boolean' => $boolean, 'column' => $column, 'operator' => $operator, 'value' => $value];
    }

    /**
     * The group of where_all(), where_any() or where_none(): the same comparison (see comparison()) on each
     * column, joined with $glue, joined to the conditions before it by $boolean, and negated with $not.
     *
     * @param array<mixed> $columns
     * @param 'AND'|'OR' $boolean
     * @param 'AND'|'OR' $glue
     * @return list<Where>
     * @throws ActiveRecordException as comparison() does, for an empty list of columns, and for a column
     *   name that is not a string, or empty
     */
    public function columns(string $method, array $columns, mixed $operator, mixed $value, int $count, string $boolean, string $glue, bool $not): array
    {
        // all, any or none of no column has no meaning: Laravel's silent "no condition" would match every row
        if ([] === $columns) {
            throw new ActiveRecordException(sprintf('%s() needs at least one column, not an empty list, on %s', $method, $this->model_class));
        }

        $columns = $this->column_list($method, $columns);
        [$operator, $value] = $this->operator_and_value($method, implode(', ', $columns), $operator, $value, $count);
        $wheres = [];

        foreach ($columns as $column) {
            $wheres[] = ['type' => 'basic', 'boolean' => $glue, 'column' => $column, 'operator' => $operator, 'value' => $value];
        }

        return $this->group($wheres, boolean: $boolean, not: $not);
    }

    /**
     * A comparison of two columns (see Query::where_column()). $count is the number of the arguments
     * given after the first column: one is the second column, compared with =.
     *
     * @param 'AND'|'OR' $boolean
     * @return WhereColumn
     * @throws ActiveRecordException for an unknown operator, an empty column name, or no second column
     */
    public function column_comparison(string $method, string $first, ?string $operator, ?string $second, int $count, string $boolean): array
    {
        $first = $this->column_name($method, $first);

        if (1 === $count) {
            [$operator, $second] = ['=', $operator];
        }

        $operator = $this->operator($method, $operator);

        if (null === $second) {
            throw new ActiveRecordException(sprintf("No second column for '%s' in %s() on %s", $first, $method, $this->model_class));
        }

        return ['type' => 'column', 'boolean' => $boolean, 'first' => $first, 'operator' => $operator, 'second' => $this->column_name($method, $second)];
    }

    /**
     * A condition written in SQL given to $method (see Query::where_raw()), read as a legacy positional condition is
     * (SQLBuilder::where()): a value that is a list is spread over its marker by Expressions, IN(?) with
     * [1, 2] being IN(?,?) and with [] IN(NULL). Without one, the SQL and the values are kept as given. SQL
     * that is empty or only whitespace is refused (see QueryCompiler::refuse_blank_sql()): alone it would be
     * left out, and a write of the query would write every row.
     *
     * @param array<mixed> $bindings
     * @param 'AND'|'OR' $boolean
     * @return WhereRaw
     * @throws ActiveRecordException for SQL that is empty or only whitespace
     * @throws ExpressionsException for more bind markers than values, when one is a list
     */
    public function raw(string $method, string $sql, array $bindings, string $boolean): array
    {
        (new QueryCompiler($this->table))->refuse_blank_sql($sql, $method);
        $bindings = array_values($bindings);

        if ([] !== array_filter($bindings, is_array(...))) {
            require_once __DIR__ . '/Expressions.php';
            $expressions = new Expressions($this->table->connection(), $sql);
            $expressions->bind_values($bindings);
            $sql = $expressions->to_s();
            $bindings = array_flatten($expressions->values());
        }

        return ['type' => 'raw', 'boolean' => $boolean, 'sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * column IN (values), or with $not NOT IN (see Query::where_in() and where_not_in()). The keys of the
     * list are not read.
     *
     * @param array<mixed> $values
     * @param 'AND'|'OR' $boolean
     * @return WhereIn
     * @throws ActiveRecordException for an empty column name, or a list that holds an array
     */
    public function in(string $method, string $column, array $values, string $boolean, bool $not): array
    {
        return ['type' => 'in', 'boolean' => $boolean, 'column' => $this->column_name($method, $column), 'values' => $this->list_values($method, $values), 'not' => $not];
    }

    /**
     * column IS NULL, or with $not IS NOT NULL, for a column or each column of a list.
     *
     * @param string|array<mixed> $columns
     * @param 'AND'|'OR' $boolean
     * @return list<WhereNull>
     * @throws ActiveRecordException for a column name that is not a string, or empty
     */
    public function nulls(string $method, string|array $columns, string $boolean, bool $not): array
    {
        $wheres = [];

        foreach ($this->column_list($method, is_array($columns) ? $columns : [$columns]) as $column) {
            $wheres[] = ['type' => 'null', 'boolean' => $boolean, 'column' => $column, 'not' => $not];
        }

        return $wheres;
    }

    /**
     * column BETWEEN two values, or with $not NOT BETWEEN. The keys of the list are not read.
     *
     * @param array<mixed> $values
     * @param 'AND'|'OR' $boolean
     * @return WhereBetween
     * @throws ActiveRecordException for an empty column name, or a list that does not hold two values, or
     *   holds an array
     */
    public function between(string $method, string $column, array $values, string $boolean, bool $not): array
    {
        $column = $this->column_name($method, $column);

        if (2 !== count($values)) {
            throw new ActiveRecordException(sprintf('%s() needs two values, not %d, on %s', $method, count($values), $this->model_class));
        }

        // read with their keys, which a refusal names
        [$low, $high] = $this->list_values($method, $values);

        return ['type' => 'between', 'boolean' => $boolean, 'column' => $column, 'values' => [$low, $high], 'not' => $not];
    }

    /**
     * column BETWEEN two columns, or with $not NOT BETWEEN.
     *
     * @param array<mixed> $columns
     * @param 'AND'|'OR' $boolean
     * @return WhereBetweenColumns
     * @throws ActiveRecordException for an empty column name, or a list that does not hold two column names
     */
    public function between_columns(string $method, string $column, array $columns, string $boolean, bool $not): array
    {
        $column = $this->column_name($method, $column);

        if (2 !== count($columns)) {
            throw new ActiveRecordException(sprintf('%s() needs two values, not %d, on %s', $method, count($columns), $this->model_class));
        }

        [$first, $second] = $this->column_list($method, $columns);

        return ['type' => 'between_columns', 'boolean' => $boolean, 'column' => $column, 'columns' => [$first, $second], 'not' => $not];
    }

    /**
     * column LIKE pattern, or with $not NOT LIKE (see Query::where_like()). The adapter refuses at the call
     * a LIKE it cannot write (spec §5.7), not when the statement is compiled: its message is kept, with the
     * method and the model.
     *
     * @param 'AND'|'OR' $boolean
     * @return WhereLike
     * @throws ActiveRecordException for an empty column name, or a case-sensitive LIKE the adapter does not write
     */
    public function like(string $method, string $column, string $value, bool $case_sensitive, string $boolean, bool $not): array
    {
        $column = $this->column_name($method, $column);

        try {
            $this->table->connection()->like_condition($column, $case_sensitive, $not);
        } catch (ActiveRecordException $e) {
            throw new ActiveRecordException(sprintf('%s, in %s() on %s', $e->getMessage(), $method, $this->model_class), 0, $e);
        }

        return ['type' => 'like', 'boolean' => $boolean, 'column' => $column, 'value' => $value, 'case_sensitive' => $case_sensitive, 'not' => $not];
    }

    /**
     * pk = id or pk IN (ids), or with $not != and NOT IN, ANDed (see Query::where_key()). The pk is
     * pre-quoted: the column itself, which no alias names, and with joins the model's (see
     * QueryCompiler::column()).
     *
     * @return WhereBasic|WhereIn
     * @throws ActiveRecordException for a model whose primary key is not one column, or a list of ids that
     *   holds an array
     */
    public function key(string $method, mixed $id, bool $not): array
    {
        if (1 !== count($this->table->pk)) {
            throw new ActiveRecordException(sprintf('%s() needs a single-column primary key; %s has %s', $method, $this->model_class, [] === $this->table->pk ? 'none' : 'a composite one'));
        }

        $column = $this->table->connection()->quote_name($this->table->real_column_name($this->table->pk[0]));

        if (is_array($id)) {
            return ['type' => 'in', 'boolean' => 'AND', 'column' => $column, 'values' => $this->list_values($method, $id), 'not' => $not];
        }

        return ['type' => 'basic', 'boolean' => 'AND', 'column' => $column, 'operator' => $not ? '!=' : '=', 'value' => $id];
    }

    /**
     * An entry of a hash of conditions, column => value, which ConditionCompiler renders as the legacy
     * finders render one (Expressions).
     *
     * @return WhereHash
     * @throws ActiveRecordException for an empty column name
     */
    public function hash(string $method, string $column, mixed $value): array
    {
        return ['type' => 'hash', 'boolean' => 'AND', 'column' => $this->column_name($method, $column), 'value' => $value];
    }

    /**
     * A group of conditions in parentheses, joined to those before it by $boolean and negated with $not,
     * as the one condition of a list; none for no conditions, since an empty group is left out.
     *
     * @param list<Where> $wheres
     * @param 'AND'|'OR' $boolean
     * @return list<Where>
     */
    public function group(array $wheres, string $boolean, bool $not): array
    {
        if ([] === $wheres) {
            return [];
        }

        $group = new QueryState();
        $group->wheres = $wheres;

        return [['type' => 'group', 'boolean' => $boolean, 'not' => $not, 'group' => $group]];
    }

    /**
     * A column name given to $method, refused when empty (see QueryCompiler::refuse_empty_column_name()).
     *
     * @throws ActiveRecordException for an empty name
     */
    private function column_name(string $method, string $name): string
    {
        (new QueryCompiler($this->table))->refuse_empty_column_name($name, $method);

        return $name;
    }

    /**
     * The operator, in upper case, and the value of a comparison of $column, from the arguments given
     * after the column, $count of them: one is the value, compared with =; two are an operator, where
     * null is =, and the value.
     *
     * @return array{string, mixed}
     * @throws ActiveRecordException for no value, an unknown operator, a null compared by another operator
     *   than =, !=, <> and <=>, or an array as the value
     */
    private function operator_and_value(string $method, string $column, mixed $operator, mixed $value, int $count): array
    {
        if ($count < 1) {
            throw new ActiveRecordException(sprintf("No value given for '%s' in %s() on %s", $column, $method, $this->model_class));
        }

        if (1 === $count) {
            [$operator, $value] = ['=', $operator];
        }

        $given = $operator ?? '=';
        $operator = $this->operator($method, $operator);

        if (null === $value && !in_array($operator, self::NULL_OPERATORS, true)) {
            throw new ActiveRecordException(sprintf("Illegal operator and value combination: '%s' with null in %s() on %s", $given, $method, $this->model_class));
        }

        if (is_array($value)) {
            // a HAVING has no IN of its own: it takes a list of values in an expression (having_raw() spreads it)
            $hint = str_contains($method, 'having') ? 'having_raw()' : 'where_in() or where_not_in()';

            throw new ActiveRecordException(sprintf("An array is no value for '%s' in %s() on %s: a list of values goes to %s", $given, $method, $this->model_class, $hint));
        }

        return [$operator, $value];
    }

    /**
     * An operator of a condition, matched in any case against OPERATORS and the adapter's own (see
     * Connection::extra_operators()), and returned in upper case; null is =. An operator with a ? is
     * refused whatever the adapter lists: it would read as a bind marker.
     *
     * @throws ActiveRecordException for any other
     */
    private function operator(string $method, mixed $operator): string
    {
        $operator ??= '=';
        $key = is_string($operator) ? strtolower($operator) : null;
        $known = null !== $key && !str_contains($key, '?')
            && (in_array($key, self::OPERATORS, true) || in_array($key, $this->table->connection()->extra_operators(), true));

        if (!$known) {
            throw new ActiveRecordException(sprintf('Unknown operator %s in %s() on %s', is_string($operator) ? "'$operator'" : get_debug_type($operator), $method, $this->model_class));
        }

        return strtoupper($operator);
    }

    /**
     * The values of a list given to $method (IN, BETWEEN, the ids of a primary key), in order, its keys
     * not read: each one value, never an array (spec §5.7).
     *
     * @param array<mixed> $values
     * @return list<mixed>
     * @throws ActiveRecordException for a list that holds an array
     */
    private function list_values(string $method, array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                throw new ActiveRecordException(sprintf('An array is no value in the list given to %s() on %s, at key %s', $method, $this->model_class, var_export($key, true)));
            }
        }

        return array_values($values);
    }

    /**
     * The column names of a list given to $method.
     *
     * @param array<mixed> $columns
     * @return list<string>
     * @throws ActiveRecordException for a name that is not a string, or an empty one
     */
    private function column_list(string $method, array $columns): array
    {
        $names = [];

        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new ActiveRecordException(sprintf('A column name must be a string, not %s, in %s() on %s', get_debug_type($column), $method, $this->model_class));
            }

            $names[] = $this->column_name($method, $column);
        }

        return $names;
    }
}
