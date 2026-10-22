<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The plain data of one Query: what the builder records and QueryCompiler turns into
 * SQLBuilder calls. Typed public properties, no behavior.
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
}
