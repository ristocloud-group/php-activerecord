<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The plain data of one Query: what the builder records and QueryCompiler turns into
 * SQLBuilder calls. Typed public properties, no behavior.
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
}
