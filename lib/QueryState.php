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
 * @internal Serves the Query engine; not a supported API.
 * @package ActiveRecord
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
     * The joins of the builder, in order, as SQL. With any, a column of the model is qualified
     * (see QueryCompiler::column()), and the default select is the model's rows only.
     *
     * @var list<string>
     */
    public array $joins = [];
}
