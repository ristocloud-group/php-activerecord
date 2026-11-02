<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The plain data of one Query: what the builder records and QueryCompiler turns into
 * SQLBuilder calls. Typed public properties, and no behavior but the deep copy that a clone makes (see
 * __clone()).
 *
 * A state holds legacy options or the builder's clauses: QueryCompiler compiles the legacy
 * options when there are any, and the clauses otherwise.
 *
 * The conditions of the WHERE ($wheres) are recorded as they were given, each joined to the one
 * before it by its boolean (that of the first is dropped): a column is read by
 * QueryCompiler::column(), and a value prepared for its column, when the statement is compiled.
 *
 * @internal Serves the Query engine; not a supported API.
 * @package ActiveRecord
 * @phpstan-type WhereBasic array{type: 'basic', boolean: 'AND'|'OR', column: string, operator: string, value: mixed}
 * @phpstan-type WhereColumn array{type: 'column', boolean: 'AND'|'OR', first: string, operator: string, second: string}
 * @phpstan-type WhereRaw array{type: 'raw', boolean: 'AND'|'OR', sql: string, bindings: list<mixed>}
 * @phpstan-type WhereIn array{type: 'in', boolean: 'AND'|'OR', column: string, values: list<mixed>, not: bool}
 * @phpstan-type WhereNull array{type: 'null', boolean: 'AND'|'OR', column: string, not: bool}
 * @phpstan-type WhereBetween array{type: 'between', boolean: 'AND'|'OR', column: string, values: array{mixed, mixed}, not: bool}
 * @phpstan-type WhereBetweenColumns array{type: 'between_columns', boolean: 'AND'|'OR', column: string, columns: array{string, string}, not: bool}
 * @phpstan-type WhereLike array{type: 'like', boolean: 'AND'|'OR', column: string, value: string, case_sensitive: bool, not: bool}
 * @phpstan-type WhereHash array{type: 'hash', boolean: 'AND'|'OR', column: string, value: mixed}
 * @phpstan-type WhereGroup array{type: 'group', boolean: 'AND'|'OR', not: bool, group: QueryState}
 * @phpstan-type Where WhereBasic|WhereColumn|WhereRaw|WhereIn|WhereNull|WhereBetween|WhereBetweenColumns|WhereLike|WhereHash|WhereGroup
 * @phpstan-type Join array{type: 'INNER'|'LEFT'|'RIGHT'|'CROSS', table: string, alias: string|null, wheres: list<Where>}
 * @phpstan-type OrderBy array{type: 'column', column: string, direction: 'ASC'|'DESC'}|array{type: 'raw', sql: string, bindings: list<mixed>}|array{type: 'random'}
 * @phpstan-type GroupBy array{type: 'column', column: string}|array{type: 'raw', sql: string, bindings: list<mixed>}
 */
final class QueryState
{
    /**
     * A legacy options array (conditions, joins, order, ...) exactly as the caller gave it:
     * the form Table::options_to_sql() receives. Null for a query that was not given one.
     *
     * @var array<string, mixed>|null
     */
    public ?array $legacy = null;

    /**
     * What the builder selects, in order (Query::select(), add_select(), select_raw()): a column
     * as it was named, which QueryCompiler::column() reads when the statement is compiled, with
     * the alias it is selected as, if any; or an SQL expression with the values of its bind
     * markers. None: the default select.
     *
     * @var list<array{column: string, alias: string|null}|array{raw: string, bindings: list<mixed>}>
     */
    public array $selects = [];

    /** Whether the builder selects each row once (Query::distinct()). */
    public bool $distinct = false;

    /**
     * The table the builder reads the rows from instead of the model's, and the alias the statement
     * gives it, if any, as QueryCompiler::table_reference() reads them from from()'s arguments.
     * Null: the model's table.
     */
    public ?string $from = null;

    public ?string $from_alias = null;

    /**
     * A FROM written in SQL (Query::from_raw()), sent as it is, in place of from()'s, and the
     * values of its bind markers.
     */
    public ?string $from_raw = null;

    /** @var list<mixed> */
    public array $from_bindings = [];

    /**
     * The joins of the builder, in order. The joins of relationships that Query::joins() and
     * left_joins() add, and the JOIN fragments joins() takes, are held as their SQL. A join of
     * Query::join(), left_join(), right_join() or cross_join() is held as its type, its table and the
     * alias it has, if any (as QueryCompiler::table_reference() reads them), and the conditions of its
     * ON, in the shapes of $wheres, as they were when the join was added; a CROSS join has none.
     *
     * With any join, a column of the model is qualified (see QueryCompiler::column()), and the
     * default select is the model's rows only.
     *
     * @var list<string|Join>
     */
    public array $joins = [];

    /**
     * The conditions of the builder's WHERE, in order (Query::where() and its family). Each has a
     * type: a comparison of a column with a value ('basic') or with a column ('column'), SQL
     * ('raw'), IN ('in'), IS NULL ('null'), BETWEEN two values ('between') or two columns
     * ('between_columns'), LIKE ('like'), an entry of a hash of conditions ('hash'), or a group
     * in parentheses ('group'), whose conditions are those of a state of their own. 'not' is the
     * negated form of the type: NOT IN, IS NOT NULL, NOT BETWEEN, NOT LIKE, or NOT (...) for a
     * group.
     *
     * @var list<Where>
     */
    public array $wheres = [];

    /**
     * The builder's GROUP BY, in order (Query::group_by() and group_by_raw()): a column as it was named,
     * which QueryCompiler::column() reads when the statement is compiled; or SQL with the values of its
     * bind markers.
     *
     * @var list<GroupBy>
     */
    public array $groups = [];

    /**
     * The conditions of the builder's HAVING, in order (Query::having() and its family): the shapes of
     * $wheres, as Conditions reads them, each joined to the one before it by its boolean (that of the
     * first is dropped). They are rendered as the conditions of the WHERE are, by the same rule for the
     * names of their columns.
     *
     * @var list<Where>
     */
    public array $havings = [];

    /**
     * The builder's ORDER BY, in order (Query::order_by() and its family): a column as it was named, which
     * QueryCompiler::column() reads when the statement is compiled, with its direction; SQL with the values
     * of its bind markers; or the random order of the adapter (Connection::random_function()).
     *
     * @var list<OrderBy>
     */
    public array $orders = [];

    /**
     * The most rows the builder selects (Query::limit(), Query::for_page()), or null for no limit. Zero is a
     * limit: it selects none (#34).
     */
    public ?int $limit = null;

    /**
     * The rows the builder skips (Query::offset(), Query::for_page()), or null when none was given. Zero is
     * a value like any other: it is compiled as the legacy 'offset' option compiles it.
     */
    public ?int $offset = null;

    /**
     * The relationships the builder eager loads with the models it finds (Query::with(), without() and
     * with_only()), in the form the legacy 'include' option takes them: a relationship that is loaded alone is a
     * list entry, and one with relationships of its model loaded under it is a key with the list of those,
     * 'author' => ['books'], to any depth. None: nothing is loaded.
     *
     * @var array<int|string, mixed>
     */
    public array $includes = [];

    /** Whether the models the builder finds are readonly (Query::readonly()), as the legacy 'readonly' option has them. */
    public bool $readonly = false;

    /**
     * A copy of a state shares no group of conditions with it: the groups of the WHERE, of the HAVING and of the
     * ON of a join are states of their own (see $wheres), and a copy that held the same ones would change with the
     * state it was made from, and change it. Everything else is a scalar, or an array, which a copy holds by value,
     * or a value that the builder binds, which it holds as it is (a DateTime is the same DateTime).
     */
    public function __clone()
    {
        $this->wheres = self::copy_groups($this->wheres);
        $this->havings = self::copy_groups($this->havings);
        $joins = [];

        foreach ($this->joins as $join) {
            if (!is_string($join)) {
                $join['wheres'] = self::copy_groups($join['wheres']);
            }

            $joins[] = $join;
        }

        $this->joins = $joins;
    }

    /**
     * The conditions $conditions, with each group of them a copy of its own, and the groups in it too (see
     * __clone()).
     *
     * @param list<Where> $conditions
     * @return list<Where>
     */
    private static function copy_groups(array $conditions): array
    {
        foreach ($conditions as $i => $condition) {
            if ('group' === $condition['type']) {
                $condition['group'] = clone $condition['group'];
                $conditions[$i] = $condition;
            }
        }

        return $conditions;
    }
}
