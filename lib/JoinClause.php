<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The ON of a join, as the closure given to Query::join(), left_join() or right_join() writes it: on() and
 * or_on() compare two columns, where() and or_where() compare a column with a value, which is bound, and
 * where_null(), where_not_null(), where_in() and where_not_in() are those of the where family. The
 * conditions are joined in the order they are added, each by its AND or OR (that of the first is dropped).
 *
 * Their columns are named by the one rule of the builder (see QueryCompiler::column()): with a join, a
 * column of the model gets the model's table, and any other name is quoted as written, so a column of the
 * joined table is written with its table, or its alias (authors.name). Each condition is read as the where
 * family reads its own (see Conditions), and what it cannot read is refused at the call that gives it
 * (spec §5.7): an unknown operator, a null that the operator cannot compare, an array where a value goes,
 * an empty column name.
 *
 * <code>
 * Book::left_join('authors', fn (JoinClause $join) => $join
 *     ->on('authors.author_id', '=', 'books.author_id')
 *     ->where('authors.name', 'Tito'))->get();
 * </code>
 *
 * @package ActiveRecord
 * @phpstan-import-type Where from QueryState
 */
final class JoinClause
{
    /**
     * The conditions of the ON, in order.
     *
     * @var list<Where>
     */
    private array $wheres = [];

    /**
     * @param string $type The type of the join: INNER, LEFT or RIGHT
     * @param string $table The table of the join, as the join method was given it
     * @param Conditions|null $conditions The reader of the conditions on the query's model, which the query
     *   gives the JoinClause it passes to the closure: one made without it takes no condition
     */
    public function __construct(public readonly string $type, public readonly string $table, private readonly ?Conditions $conditions = null) {}

    /**
     * Adds a comparison of two columns, ANDed: on('authors.author_id', '=', 'books.author_id'), or with two
     * columns only, on('authors.author_id', 'books.author_id'), compared with =. It takes the operators
     * where() takes.
     *
     * @return $this
     * @throws ActiveRecordException for an unknown operator, an empty column name, or no second column
     */
    public function on(string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->add([$this->conditions(__FUNCTION__)->column_comparison(__FUNCTION__, $first, $operator, $second, func_num_args() - 1, boolean: 'AND')]);
    }

    /**
     * Adds a comparison of two columns as on() does, ORed.
     *
     * @return $this
     * @throws ActiveRecordException as on() does
     */
    public function or_on(string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->add([$this->conditions(__FUNCTION__)->column_comparison(__FUNCTION__, $first, $operator, $second, func_num_args() - 1, boolean: 'OR')]);
    }

    /**
     * Adds a comparison of a column with a value, ANDed, the value bound: where('authors.name', 'Tito'), or
     * with an operator, where('authors.author_id', '>', 2), as Query::where() takes them; a null is compared
     * with IS NULL by = and <=>, and with IS NOT NULL by != and <>.
     *
     * @return $this
     * @throws ActiveRecordException for an unknown operator, a null compared by another operator than =, !=,
     *   <> and <=>, an array as the value, no value, or an empty column name
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add([$this->conditions(__FUNCTION__)->comparison(__FUNCTION__, $column, $operator, $value, func_num_args() - 1, boolean: 'AND')]);
    }

    /**
     * Adds a comparison of a column with a value as where() does, ORed.
     *
     * @return $this
     * @throws ActiveRecordException as where() does
     */
    public function or_where(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add([$this->conditions(__FUNCTION__)->comparison(__FUNCTION__, $column, $operator, $value, func_num_args() - 1, boolean: 'OR')]);
    }

    /**
     * Adds column IS NULL, ANDed.
     *
     * @return $this
     * @throws ActiveRecordException for an empty column name
     */
    public function where_null(string $column): static
    {
        return $this->add($this->conditions(__FUNCTION__)->nulls(__FUNCTION__, $column, boolean: 'AND', not: false));
    }

    /**
     * Adds column IS NOT NULL, ANDed.
     *
     * @return $this
     * @throws ActiveRecordException for an empty column name
     */
    public function where_not_null(string $column): static
    {
        return $this->add($this->conditions(__FUNCTION__)->nulls(__FUNCTION__, $column, boolean: 'AND', not: true));
    }

    /**
     * Adds column IN (values), ANDed, by the rules of Query::where_in(): an empty list is 1=0, and a null in
     * the list is compared with IS NULL.
     *
     * @param array<mixed> $values
     * @return $this
     * @throws ActiveRecordException for an empty column name, or a list that holds an array
     */
    public function where_in(string $column, array $values): static
    {
        return $this->add([$this->conditions(__FUNCTION__)->in(__FUNCTION__, $column, $values, boolean: 'AND', not: false)]);
    }

    /**
     * Adds column NOT IN (values), ANDed, by the rules of Query::where_not_in(): an empty list is 1=1, and a
     * null in the list makes the column IS NOT NULL too.
     *
     * @param array<mixed> $values
     * @return $this
     * @throws ActiveRecordException for an empty column name, or a list that holds an array
     */
    public function where_not_in(string $column, array $values): static
    {
        return $this->add([$this->conditions(__FUNCTION__)->in(__FUNCTION__, $column, $values, boolean: 'AND', not: true)]);
    }

    /**
     * The conditions of the ON, in order, in the shapes of QueryState::$wheres.
     *
     * @internal Serves Query; not a supported API.
     * @return list<Where>
     */
    public function wheres(): array
    {
        return $this->wheres;
    }

    /**
     * Adds conditions to the ON, after those it holds.
     *
     * @param list<Where> $wheres
     * @return $this
     */
    private function add(array $wheres): static
    {
        $this->wheres = [...$this->wheres, ...$wheres];

        return $this;
    }

    /**
     * The reader of the conditions given to $method, which a JoinClause made by hand lacks.
     *
     * @throws ActiveRecordException for a JoinClause made without one
     */
    private function conditions(string $method): Conditions
    {
        return $this->conditions ?? throw new ActiveRecordException(sprintf('%s() needs the JoinClause of a query: the closure given to join(), left_join() or right_join() gets one', $method));
    }
}
