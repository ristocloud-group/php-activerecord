<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

use Closure;

/**
 * Turns a QueryState into the SQLBuilder that renders it: the one place that does.
 *
 * @internal Serves the Query engine; not a supported API.
 * @package ActiveRecord
 * @phpstan-import-type Where from QueryState
 * @phpstan-import-type GroupBy from QueryState
 * @phpstan-import-type OrderBy from QueryState
 */
final class QueryCompiler
{
    /**
     * The fields of a state that an UPDATE and a DELETE compile (see apply_write_clauses()): the legacy options of the
     * legacy writes, or the where, the order and the limit of the builder, which pick the rows they write (a where call
     * that added no condition among them, and a when() or an unless() that skipped its callback, see
     * refuse_empty_where()). Every other field is refused (see refuse_unwritable_clauses()).
     *
     * @var list<string>
     */
    private const array UPDATE_DELETE_FIELDS = ['legacy', 'wheres', 'empty_where', 'skipped_when', 'orders', 'limit'];

    /**
     * The name a refusal gives a field of a state, where it is not the field's own: the builder method, or the family
     * of methods, that sets it.
     *
     * @var array<string, string>
     */
    private const array CLAUSE_NAMES = [
        'legacy' => 'legacy options',
        'selects' => 'select',
        'from_alias' => 'from',
        'from_bindings' => 'from_raw',
        'wheres' => 'where',
        'empty_where' => 'where',
        'skipped_when' => 'a when() or unless() that skipped its callback',
        'groups' => 'group_by',
        'havings' => 'having',
        'orders' => 'order_by',
        'includes' => 'with',
    ];

    /**
     * The keys (see Connection::column_name_key()) of the names of the model's columns (see
     * model_column_names()), read once.
     *
     * @var array<string, true>|null
     */
    private ?array $model_column_keys = null;

    /**
     * The columns of the model's table by the keys of their names (see Connection::column_name_key()), read
     * once (see model_column()).
     *
     * @var array<string, Column>|null
     */
    private ?array $columns_by_key = null;

    /**
     * The statements are rendered for $connection when one is given, else for $table's: a bulk write
     * of Model gives the connection it always built its statement on (see Query::execute_update()).
     */
    public function __construct(private readonly Table $table, private readonly ?Connection $connection = null) {}

    /**
     * A pk value of a finder as the column stores it: a DateTime for a DATE pk column as the date the column
     * stores (like Table::process_data() does for an update/delete). A finder binds its values positionally, in the
     * datetime format ('2026-01-02 00:00:00' or '2026-01-02 14:00:00'), which SQLite compares as text with the
     * stored '2026-01-02' and MySQL/MariaDB compare as a datetime. Any other value, or a DateTime for another
     * column type, is returned as is. $name names the pk column as the model does, by the column or its
     * attribute name.
     *
     * Moved here unchanged from Model (date_pk_finder_value()), so that the legacy finders by pk
     * (Model::find_by_pk() and its family, Model::reload()) and the builder's (Query::find() and its family)
     * share it. The date is written by the connection that $connection returns when it is given, else by the
     * Table's: the legacy finders give Model::connection(), the public static a subclass can override, which
     * they always asked here, and only for a DateTime of a DATE pk.
     *
     * @internal Serves the finders by pk of Model and Query; not a supported API.
     * @param (Closure(): Connection)|null $connection
     */
    public static function pk_value_for_column(Table $table, string $name, mixed $value, ?Closure $connection = null): mixed
    {
        if (!$value instanceof \DateTimeInterface) {
            return $value;
        }

        $column = $table->columns[$name] ?? $table->get_column_by_inflected_name($name);

        return null !== $column && Column::DATE === $column->type ? self::pk_connection($table, $connection)->date_to_string($value) : $value;
    }

    /**
     * Leaves out of $values (a finder's pk value, or list of values) those the connection
     * cannot compare with an INTEGER pk column: on Postgres 'abc' fails the query (22P02)
     * and aborts the open transaction, while it can only match no row (#92). Values for
     * another column type, and values that are all accepted, are left as they are.
     * When no value is left no query runs, so the table's last_sql still shows the
     * previous query.
     *
     * Moved here unchanged from Model (keep_integer_pk_finder_values()), to be shared as
     * pk_value_for_column() is, which says which connection checks the values.
     *
     * @internal Serves the finders by pk of Model and Query; not a supported API.
     * @param (Closure(): Connection)|null $connection
     * @return bool False when no value is left, so there is nothing to query
     */
    public static function keep_integer_pk_values(Table $table, string $name, mixed &$values, ?Closure $connection = null): bool
    {
        $column = $table->columns[$table->real_column_name($name)] ?? null;

        if (null === $column || Column::INTEGER !== $column->type) {
            return true;
        }

        $connection = self::pk_connection($table, $connection);

        if (!is_array($values)) {
            return $connection->accepts_integer_value($values);
        }

        $accepted = array_filter($values, $connection->accepts_integer_value(...));

        if (count($accepted) === count($values)) {
            return true;
        }
        $values = array_values($accepted);

        return [] !== $values;
    }

    /**
     * SQL written by hand for the builder (select_raw(), from_raw(), a JOIN fragment of joins(), where_raw(),
     * group_by_raw(), having_raw(), order_by_raw() and their or_ forms) as the statement writes it: as given,
     * followed by a newline when it holds -- or # and does not end with a newline already. Either can open a line
     * comment (# on MySQL and MariaDB), which would otherwise run to the end of the statement and swallow what is
     * written after the fragment: the ORDER BY and LIMIT of a limited write, which would then write every row it
     * matches, or the parenthesis that closes a group. The newline ends the comment. SQL without them is written as
     * given. A statement terminator (;) would end the statement as surely: the methods refuse it at the call (see
     * refuse_statement_terminator()). The legacy options never come here: their SQL is sent as given.
     *
     * @internal Serves QueryCompiler and ConditionCompiler; not a supported API.
     */
    public static function raw_fragment(string $sql): string
    {
        return (str_contains($sql, '--') || str_contains($sql, '#')) && !str_ends_with($sql, "\n") ? "$sql\n" : $sql;
    }

    /**
     * The SELECT of a state: that of its legacy options when it holds some, as the legacy entries always
     * sent it, else that of the builder's clauses. A state that holds nothing selects every row.
     *
     * $conditions are the engine's own, in the shapes of the builder's (QueryState::$wheres): the primary
     * key of a find (see Query::find()). They come first in the WHERE, ANDed, and the builder's WHERE
     * follows in parentheses (see where_clause()).
     *
     * @param list<Where> $conditions
     * @throws ActiveRecordException for a state that holds legacy options and builder clauses both, or
     *   legacy options and conditions of the engine: the one would be compiled and the other dropped
     */
    public function compile_select(QueryState $state, array $conditions = []): SQLBuilder
    {
        if (null === $state->legacy) {
            return $this->compile_clauses($state, $conditions);
        }

        if ([] !== $conditions || self::holds_clauses($state)) {
            throw new ActiveRecordException(sprintf('A query on %s holds both legacy options and builder clauses: it compiles the one or the other', $this->table->class->name));
        }

        return $this->compile_legacy($state->legacy);
    }

    /**
     * The SELECT that counts the rows of a state, for Query::count(); or with a $column other than *, the
     * rows in which that column is not null, as COUNT(column) counts. It is the aggregate COUNT of
     * compile_aggregate(), whose rules (counted in place, or as a subquery) it follows.
     *
     * @internal Serves Query::count(); not a supported API.
     * @throws ActiveRecordException for a state of legacy options (see compile_select())
     */
    public function compile_count(QueryState $state, string $column): SQLBuilder
    {
        return $this->compile_aggregate($state, $column, ['COUNT']);
    }

    /**
     * The SELECT that applies aggregate functions to a column of the rows of a state, for Query::count() and the
     * aggregates of the builder (min(), max(), sum(), avg()): each function of $functions (COUNT, MIN, MAX, SUM or AVG)
     * to $column, in one row, in the order given. With COUNT alone, $column may be *, which counts the rows; with a
     * column, it counts those in which the column is not null, as COUNT(column) counts.
     *
     * A state neither grouped (GROUP BY, HAVING), nor distinct, nor windowed (LIMIT, OFFSET) is aggregated in
     * place: SELECT COUNT(*) with its FROM, joins and WHERE, without its order, which changes no number
     * (Postgres refuses an order by a column beside an aggregate, besides). Any other is aggregated as a subquery,
     * SELECT COUNT(*) FROM (…) AS t, which keeps its order only when windowed, since there the order picks
     * the rows. So the aggregate is that of the rows of the result, as count() counts them: a grouped query counts
     * its groups, and a window's rows are the ones it picks.
     *
     * What the subquery selects:
     * - 1, only where the state's select provably cannot matter: no column is aggregated, no DISTINCT tells
     *   the rows apart by the select, no HAVING may name an alias of it, no SQL written by hand in the GROUP
     *   BY, nor in the ORDER BY the subquery keeps (a window's), may name it by position or expression
     *   (GROUP BY 1 would group by the constant; see has_raw()), and it holds plain columns without an
     *   alias only (see plain_columns()), or nothing. A plain column carries no alias another
     *   clause could name, and no aggregate that collapses the rows. Such a select is still not kept, for
     *   one that gives two result columns one name (a joined table's name beside the model's) is refused in
     *   a derived table by MySQL and MariaDB (1060), and one of every column beside a GROUP BY by Postgres
     *   and by MySQL's ONLY_FULL_GROUP_BY.
     * - Otherwise the state's own select, since a clause or the rows may depend on it: a GROUP BY and an
     *   ORDER BY can name an alias of it, on every database, a HAVING too on MySQL, MariaDB and SQLite, SQL
     *   of a GROUP BY or a kept ORDER BY can name a column of it by position, and an aggregate
     *   (COUNT(*) AS n) makes one row of the rows. With nothing of its own, it selects the aggregated column,
     *   under DISTINCT too, so that a distinct aggregate of a column runs over its distinct values that are not
     *   null (Eloquent's COUNT(DISTINCT column)); and every column under DISTINCT for a count of the rows, or 1
     *   beside a HAVING. The order a window keeps there must then be by that column: the terminals refuse another
     *   (see refuse_distinct_window_ordered_by_another_column()).
     *
     * An aggregated column is read as t's column of its name (see result_name()), which the subquery must
     * select. Beside a GROUP BY, the column must be grouped (or be what the state's select gives, or the groups be the
     * model's whole primary key, on which every column depends): MySQL's ONLY_FULL_GROUP_BY and Postgres refuse any
     * other, and MariaDB and SQLite read it from an arbitrary row of each group, silently. min(), max(), sum() and avg()
     * refuse it at the call when they can tell (see refuse_ungrouped_column(); a select of the query's own, or SQL in
     * group_by_raw(), they cannot read); count($column) does not, and keeps that residual. With the state's select kept,
     * two result columns of one name still meet MySQL's and MariaDB's 1060.
     *
     * @internal Serves Query; not a supported API.
     * @param list<'COUNT'|'MIN'|'MAX'|'SUM'|'AVG'> $functions
     * @throws ActiveRecordException for a state of legacy options (see compile_select())
     */
    public function compile_aggregate(QueryState $state, string $column, array $functions): SQLBuilder
    {
        $all = '*' === $column;
        $windowed = null !== $state->limit || null !== $state->offset;
        $rows = clone $state;
        $apply = fn(string $expression): string => implode(', ', array_map(fn(string $function): string => "$function($expression)", $functions));

        if (!$windowed) {
            $rows->orders = [];
        }

        if (!$windowed && !$state->distinct && [] === $state->groups && [] === $state->havings) {
            $rows->selects = [['raw' => $apply($all ? '*' : $this->column($column, $state)), 'bindings' => []]];

            return $this->compile_select($rows);
        }

        if ($all && !$state->distinct && [] === $state->havings && self::plain_columns($state->selects) && !self::has_raw($state->groups) && !self::has_raw($rows->orders)) {
            // the state's select provably cannot matter
            $rows->selects = [['raw' => '1', 'bindings' => []]];
        } elseif ([] === $state->selects && !($all && $state->distinct)) {
            // under DISTINCT, the distinct values of the aggregated column: SELECT DISTINCT * would keep every row apart
            $rows->selects = [$all ? ['raw' => '1', 'bindings' => []] : ['column' => $column, 'alias' => null]];
        }

        $subquery = $this->compile_select($rows);
        $aggregate = new SQLBuilder($this->connection(), "({$subquery->to_s()}) AS t");
        $aggregate->from_values($subquery->select_bind_values());
        $aggregate->select($apply($all ? '*' : 't.' . $this->connection()->quote_name($this->result_name($column))));

        return $aggregate;
    }

    /**
     * The SELECT whose rows Query::exists() asks for. That of a state of legacy options is its own:
     * Model::exists() takes out of them what the legacy exists leaves out. The builder's leaves out its
     * order, its limit and its offset, and their values, as the legacy exists does (spec §5.3).
     *
     * Without a GROUP BY and a HAVING it selects 1, as the legacy exists does, whatever the state selects:
     * an aggregate (COUNT(*) AS n) selects one row when no row matches, which would exist. No other clause
     * names an alias of the select there: the order is left out, and a WHERE cannot name one, except on
     * SQLite, which reads one there; such a query, which runs on SQLite alone, fails in exists(). Eloquent's
     * exists() keeps the select.
     *
     * With a GROUP BY or a HAVING, which may name an alias of the select (a GROUP BY on every database, a
     * HAVING on MySQL, MariaDB and SQLite), it keeps the state's select: grouped, an aggregate selects no row
     * when no row matches. With nothing of its own it selects 1, since a select of every column is refused
     * beside a GROUP BY by Postgres and by MySQL's ONLY_FULL_GROUP_BY.
     *
     * @internal Serves Query::exists(); not a supported API.
     */
    public function compile_exists(QueryState $state): SQLBuilder
    {
        if (null !== $state->legacy) {
            return $this->compile_select($state);
        }

        $rows = clone $state;
        $rows->orders = [];
        $rows->limit = null;
        $rows->offset = null;

        if (([] === $rows->groups && [] === $rows->havings) || [] === $rows->selects) {
            $rows->selects = [['raw' => '1', 'bindings' => []]];
        }

        return $this->compile_select($rows);
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
     * Refuses an empty column name given to a condition or an order of the builder (see Conditions), as
     * select() and from() refuse theirs. The name itself is read by column() when the statement is compiled.
     *
     * @internal Serves Conditions and Query; not a supported API.
     * @param string $method The builder method the name was given to, for the message of the exception
     * @throws ActiveRecordException for an empty name
     */
    public function refuse_empty_column_name(string $name, string $method): void
    {
        $this->refuse_empty('column name', $name, $name, $method);
    }

    /**
     * Refuses SQL written by hand that its clause would lose (spec §5.7), given to $method: where_raw(), having_raw() and
     * their or_ forms, order_by_raw() or group_by_raw(). SQLBuilder writes a WHERE, a HAVING, an ORDER BY and a GROUP BY
     * only when its text is truthy in PHP, so text that is empty or only whitespace is refused: alone it would be left
     * out, a WHERE that matches every row and a write that writes every row, and beside another it fails at the database.
     *
     * With $position, the text 0 too: an ORDER BY and a GROUP BY read it as the position of a column, which no statement
     * has (positions start at 1), and the databases refuse it, but SQLBuilder leaves out a lone "0", which would turn
     * the error into another result. A WHERE and a HAVING keep a "0" (see ConditionCompiler::compile()).
     *
     * @internal Serves Conditions and Query; not a supported API.
     * @param string $method The builder method the SQL was given to, for the message of the exception
     * @throws ActiveRecordException
     */
    public function refuse_blank_sql(string $sql, string $method, bool $position = false): void
    {
        $this->refuse_empty('SQL', $sql, $sql, $method);

        if ($position && '0' === trim($sql)) {
            throw new ActiveRecordException(sprintf("SQL '%s' in %s() on %s names the column at position 0, which no statement has: positions start at 1", $sql, $method, $this->table->class->name));
        }
    }

    /**
     * Refuses SQL written by hand that holds a statement terminator, a ; outside its strings, its quoted identifiers and
     * its comments (see RawSqlWriter::holds_statement_terminator()), given to $source: a builder method that sends its SQL
     * as written (select_raw(), from_raw(), a JOIN fragment of joins(), where_raw(), group_by_raw(), having_raw(),
     * order_by_raw() and the or_ forms), at the call (spec §5.7), or the legacy 'conditions' option, before any
     * statement (see refuse_terminated_conditions()). MySQL and MariaDB end the statement there and run what follows it
     * as another statement, whose error is never read, and SQLite drops what follows it: either way the clauses after it
     * are lost, those of a limited write its ORDER BY and its LIMIT, so it writes every row it matches. Postgres refuses
     * the statement. A ; in a string, a quoted identifier, a comment or a bound value is no terminator, and a value is
     * bound with a ? marker.
     *
     * @internal Serves Conditions and Query, and the legacy conditions; not a supported API.
     * @param string $source Where the SQL was given, for the message of the exception: "where_raw()", "the 'conditions'
     *   option", ...
     * @throws ActiveRecordException
     */
    public function refuse_statement_terminator(string $sql, string $source): void
    {
        if (RawSqlWriter::holds_statement_terminator($sql)) {
            throw new ActiveRecordException(sprintf(
                "SQL '%s' in %s on %s holds a statement terminator (;) outside its strings and comments: MySQL, MariaDB and SQLite would end the statement there and drop the clauses after it, and Postgres would refuse it. Bind a value with a ? marker",
                $sql,
                $source,
                $this->table->class->name,
            ));
        }
    }

    /**
     * Refuses, at the call of the write $method (update(), delete(), insert() or upsert()), a state that holds a clause
     * its statement cannot use, as spec §5.5 has it: the write would drop the clause, and write other rows than the query
     * reads, or every row. A clause is a field of the state, other than those of $writable, that is not as in a new state,
     * so a clause undone (readonly(false), distinct(false)) is none, and a field that QueryState gains later is refused
     * until a write compiles it. An UPDATE and a DELETE take the where, the order and the limit (see compile_update() and
     * compile_delete()); an INSERT and an UPSERT, which write the rows they are given, take none, and name a when() or an
     * unless() that skipped its callback as such, not as a where the caller never wrote.
     *
     * @internal Serves Query; not a supported API.
     * @param list<string> $writable The fields of the state that the statement of $method compiles
     * @throws ActiveRecordException naming every clause the state holds, in the order of its fields, and what the write takes
     */
    public function refuse_unwritable_clauses(QueryState $state, string $method, array $writable = []): void
    {
        $held = [];

        foreach (self::held_fields($state, $writable) as $field) {
            $held[self::CLAUSE_NAMES[$field] ?? $field] = true;
        }

        if ([] === $held) {
            return;
        }

        $takes = [];

        // the legacy options and a skipped when() are no clause a write takes: the where stands for it
        foreach (array_diff($writable, ['legacy', 'skipped_when']) as $field) {
            $takes[self::CLAUSE_NAMES[$field] ?? $field] = true;
        }

        throw new ActiveRecordException(sprintf(
            '%s() cannot use %s, on %s: %s',
            $method,
            self::listing(array_keys($held), 'or'),
            $this->table->class->name,
            [] === $takes ? 'it takes no clause' : 'it takes only ' . self::listing(array_keys($takes), 'and'),
        ));
    }

    /**
     * Refuses, at the call, a column that a grouped query does not group, given to min(), max(), sum() or avg() (spec 5.4).
     * Their statement (see compile_aggregate()) selects the column in a subquery beside the GROUP BY. MySQL
     * (ONLY_FULL_GROUP_BY, 1055) and Postgres (42803) refuse that statement, but MariaDB in its default mode and SQLite run
     * it, and read the column of an arbitrary row of each group: a number that is wrong, with no error.
     *
     * It is refused only when it can be told that the column is not grouped: the state has a GROUP BY and no select of its
     * own (a select can aggregate the column, or name it by an alias), every group is a structured column of the model
     * (SQL of group_by_raw() may group by anything, and so may a qualified name, which this does not read), and so is the
     * aggregated column, and it is none of them. Names are compared as the columns they name, so an alias, an attribute
     * name and the column's own name are one (see model_column()). In any other case the query goes to the database as it
     * is, which may refuse it. count($column) is not checked: its residual is documented (see compile_aggregate()).
     *
     * Nor is a column refused when the groups cover the primary key of the model (Table::$pk), every column of a composite
     * one: each group is then one row of the table, and every column of it depends on the groups. MySQL and Postgres accept
     * that, and no database reads a row of its own choice (see covers_primary_key()).
     *
     * @internal Serves Query; not a supported API.
     * @param string $method The aggregate the column was given to, for the message of the exception
     * @throws ActiveRecordException for a column that is not grouped
     */
    public function refuse_ungrouped_column(QueryState $state, string $column, string $method): void
    {
        if ([] === $state->groups || [] !== $state->selects) {
            return;
        }

        $aggregated = $this->model_column($column);

        if (null === $aggregated) {
            return;
        }

        $grouped_names = [];

        foreach ($state->groups as $group) {
            $grouped = 'column' === $group['type'] ? $this->model_column($group['column']) : null;

            if (null === $grouped || $grouped->name === $aggregated->name) {
                return;
            }

            $grouped_names[$grouped->name] = true;
        }

        if ($this->covers_primary_key($grouped_names)) {
            return;
        }

        throw new ActiveRecordException(sprintf(
            "%s() on %s aggregates '%s', which the GROUP BY of the query does not group: MariaDB (default mode) and SQLite pick an arbitrary row per group; MySQL and Postgres refuse it unless the groups cover the primary key. Group by it, or give the query a select of its own",
            $method,
            $this->table->class->name,
            $column,
        ));
    }

    /**
     * Refuses, at the call, a distinct aggregate of a column in a window ordered by another column, given to $method:
     * count() of a column, min(), max(), sum() or avg() (spec §5.4). With distinct() and no select of the query's own, the
     * subquery of the aggregate selects DISTINCT that column alone (see compile_aggregate()), and a window (a limit or an
     * offset) keeps the query's order there, where it picks the values. An order by another column cannot go with it:
     * MySQL (3065) and Postgres (42P10) refuse the statement, and MariaDB and SQLite run it and pick the values
     * arbitrarily, a wrong number with no error. Without a window the order is dropped, and the statement runs.
     *
     * An order names the aggregated column when both name it alike as the statement qualifies them with joins (see
     * qualified_reference()): by an alias, an attribute name or the column's own name, qualified with the name the
     * statement reads the model's rows under or not. SQL of order_by_raw() is not read, and the random order names no
     * column (Postgres refuses it there, the other databases pick random values): such a query goes to the database as
     * it is.
     *
     * @internal Serves Query; not a supported API.
     * @param string $method The aggregate the column was given to, for the message of the exception
     * @throws ActiveRecordException for a window ordered by another column
     */
    public function refuse_distinct_window_ordered_by_another_column(QueryState $state, string $column, string $method): void
    {
        if ('*' === $column || !$state->distinct || [] !== $state->selects || (null === $state->limit && null === $state->offset)) {
            return;
        }

        $aggregated = $this->qualified_reference($column, $state);

        foreach ($state->orders as $order) {
            if ('column' === $order['type'] && $this->qualified_reference($order['column'], $state) !== $aggregated) {
                throw new ActiveRecordException(sprintf(
                    "%s() on %s aggregates the distinct values of '%s' in a window ordered by '%s', another column: SELECT DISTINCT of one column cannot be ordered by another (MySQL and Postgres refuse it, and MariaDB and SQLite pick the values arbitrarily). Order by '%s', or give the query a select of its own",
                    $method,
                    $this->table->class->name,
                    $column,
                    $order['column'],
                    $column,
                ));
            }
        }
    }

    /**
     * The WHERE of the builder's conditions (Query::where() and its family), without the keyword,
     * and the values of its bind markers in order; null when it has none. With $conditions, the
     * engine's own conditions (the primary key of a find, ...), each one condition with its values,
     * they come first, ANDed, and the builder's WHERE follows in parentheses: an OR in it never
     * widens them (spec §5.2), as the legacy finders keep their pk apart from the conditions they
     * are given (#149). A WHERE in parentheses already is not wrapped a second time (see enclosed()).
     *
     * @internal Serves Query; not a supported API.
     * @param list<array{sql: string, values: list<mixed>}> $conditions
     * @return array{sql: string, values: list<mixed>}|null
     */
    public function where_clause(QueryState $state, array $conditions = []): ?array
    {
        $own = [] === $state->wheres ? null : $this->conditions($state)->compile($state->wheres);

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
            $sql[] = self::enclosed($state->wheres, $own['sql']) ? $own['sql'] : "({$own['sql']})";
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
     * The column of the model's table that $name names by the one rule (see column()): an unqualified
     * name by its alias, its attribute name or its own name, or a pre-quoted one by its text, matched as
     * the database matches column names. Null for any other name (a qualified one, *, a joined table's
     * column, the alias of an expression) and for the columns no schema lists (rowid, ...). A value read
     * of it is cast by it, as a model casts its attribute (see Query::value()).
     *
     * @internal Serves Query; not a supported API.
     */
    public function model_column(string $name): ?Column
    {
        $parts = $this->name_parts($name);

        if (null === $parts || 1 !== count($parts) || '*' === $name) {
            return null;
        }

        $connection = $this->connection();

        if (null === $this->columns_by_key) {
            $this->columns_by_key = [];

            foreach ($this->table->columns as $column_name => $column) {
                $this->columns_by_key[$connection->column_name_key((string) $column_name)] ??= $column;
            }
        }

        return $this->columns_by_key[$connection->column_reference_key($parts[0][1] ? $name : $this->named_column($name))] ?? null;
    }

    /**
     * The UPDATE of a state, for update() and the legacy writes. $set is a hash whose keys are the columns' real names
     * and whose values are prepared (see Query::execute_update()), or a raw SQL string; SQLBuilder rejects anything
     * else. The WHERE, ORDER BY and LIMIT are the state's: those of its legacy options, or of the builder (see
     * apply_write_clauses()).
     *
     * @param array<string, mixed>|string $set
     * @throws ActiveRecordException for a state that holds a clause an UPDATE cannot use (see refuse_unwritable_clauses()),
     *   or legacy options and builder clauses both, where calls that all added no condition (see refuse_empty_where()),
     *   or a WHERE or an ORDER BY that compiles to nothing
     */
    public function compile_update(QueryState $state, array|string $set): SQLBuilder
    {
        $this->refuse_unwritable_clauses($state, 'update', self::UPDATE_DELETE_FIELDS);
        $this->refuse_empty_where($state, 'update');
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->update($set);
        $this->apply_write_clauses($sql, $state, 'update');

        return $sql;
    }

    /**
     * The DELETE of a state, for delete() and the legacy writes, whose WHERE, ORDER BY and LIMIT are the state's (see
     * apply_write_clauses()). A state without conditions deletes every row.
     *
     * @throws ActiveRecordException as compile_update() does
     */
    public function compile_delete(QueryState $state): SQLBuilder
    {
        $this->refuse_unwritable_clauses($state, 'delete', self::UPDATE_DELETE_FIELDS);
        $this->refuse_empty_where($state, 'delete');
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->delete();
        $this->apply_write_clauses($sql, $state, 'delete');

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
     * The INSERT of $row_count rows of $columns, one statement of several VALUES (see SQLBuilder::insert_rows()), for
     * Query::insert(). The names are the columns' real names, unquoted. The statement holds no values: the caller binds
     * them row after row, each in the order of $columns, and has checked that there are rows and columns.
     *
     * @internal Serves Query::insert(); not a supported API.
     * @param list<string> $columns
     */
    public function compile_insert_rows(array $columns, int $row_count): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->insert_rows($columns, $row_count);

        return $sql;
    }

    /**
     * The values of a write of the builder (Query::update(), Query::insert()) keyed by the columns their keys name, by
     * the rule of column() (spec §5.5): an alias of the model's $alias_attribute names the column of its attribute, an
     * attribute name its column (#92), and any other key is kept as written, a column's own name among them. A key of a
     * column of the model is its real name, matched as the database matches names (see model_column()): 'some_date' is
     * the column some_Date on MySQL too, where Table maps no attribute name to a column that differs from it by case only,
     * so that Table::process_data() prepares the value by that column (a DateTime of a DATE as the date). An alias never
     * shadows a column (spec §6.2.6), so the mapping is never ambiguous. Two keys that name one column leave one entry,
     * the later value, as Table::real_column_keys() leaves them. The legacy writes map attribute names only
     * (update_all()'s 'set', upsert(), Table::insert() and update()).
     *
     * @internal Serves Query; not a supported API.
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function value_columns(array $values): array
    {
        $aliases = $this->alias_attributes();
        $columns = [];

        foreach ($values as $key => $value) {
            // (string): an integer key ("0") names no column and comes back unchanged
            $key = (string) $key;
            $columns[$this->model_column($key)->name ?? $this->table->real_column_name($aliases[$key] ?? $key)] = $value;
        }

        return $columns;
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
     * table or the FROM that from() or from_raw() gives, with the joins, the WHERE, the GROUP BY, the HAVING,
     * the ORDER BY and the window (LIMIT and OFFSET). The engine's $conditions come first in the WHERE (see
     * compile_select()).
     *
     * @param list<Where> $conditions
     */
    private function compile_clauses(QueryState $state, array $conditions = []): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->from_clause($state));
        $sql->from_values($state->from_bindings);

        if ([] !== $state->joins) {
            $joins = $this->joins_clause($state);
            $sql->joins($joins['sql']);
            $sql->join_values($joins['values']);
        }

        $columns = [];
        $values = [];

        foreach ($state->selects as $select) {
            if (array_key_exists('raw', $select)) {
                $columns[] = self::raw_fragment($select['raw']);
                $values = [...$values, ...$select['bindings']];
            } else {
                $alias = null === $select['alias'] ? '' : ' AS ' . $this->connection()->quote_name($select['alias']);
                $columns[] = $this->column($select['column'], $state) . $alias;
            }
        }

        $columns = [] === $columns ? $this->default_select([] !== $state->joins, $this->rows_name($state)) : implode(', ', $columns);
        $sql->select(($state->distinct ? 'DISTINCT ' : '') . $columns);
        $sql->select_values($values);

        // each condition of the engine is one condition, rendered as those of the WHERE are
        $rendering = $this->conditions($state);
        $where = $this->where_clause($state, array_map(fn(array $condition): array => $rendering->compile([$condition]), $conditions));

        if (null !== $where) {
            $sql->where_condition($where['sql'], $where['values']);
        }

        $group = $this->group_clause($state);

        if (null !== $group) {
            $sql->group($group['sql']);
            $sql->group_values($group['values']);
        }

        // rendered as the conditions of the WHERE are, by the same rules
        if ([] !== $state->havings) {
            $having = $this->conditions($state)->compile($state->havings);
            $sql->having($having['sql']);
            $sql->having_values($having['values']);
        }

        $order = $this->order_clause($state);

        if (null !== $order) {
            $sql->order($order['sql']);
            $sql->order_values($order['values']);
        }

        // the window goes through the calls that the legacy 'limit' and 'offset' options make (see
        // compile_legacy()), so that an offset without a limit (#130) and a limit of 0 (#34) are the same there
        if (null !== $state->limit) {
            $sql->limit($state->limit);
        }

        if (null !== $state->offset) {
            $sql->offset($state->offset);
        }

        return $sql;
    }

    /**
     * The GROUP BY of the builder's groups, without the keywords, and the values of its bind markers in order;
     * null when it has none. A column is named by the one rule (see column()), and SQL written by hand is sent
     * as it is (see raw_fragment()).
     *
     * @return array{sql: string, values: list<mixed>}|null
     */
    private function group_clause(QueryState $state): ?array
    {
        $sql = [];
        $values = [];

        foreach ($state->groups as $group) {
            if ('raw' === $group['type']) {
                $sql[] = self::raw_fragment($group['sql']);
                $values = [...$values, ...$group['bindings']];
            } else {
                $sql[] = $this->column($group['column'], $state);
            }
        }

        return [] === $sql ? null : ['sql' => implode(', ', $sql), 'values' => $values];
    }

    /**
     * The ORDER BY of the builder's orders, without the keywords, and the values of its bind markers in order;
     * null when it has none. A column is named by the one rule (see column()) and followed by its direction,
     * SQL written by hand is sent as it is (see raw_fragment()), and the random order is the adapter's random
     * function (see Connection::random_function()).
     *
     * @return array{sql: string, values: list<mixed>}|null
     */
    private function order_clause(QueryState $state): ?array
    {
        $sql = [];
        $values = [];

        foreach ($state->orders as $order) {
            if ('raw' === $order['type']) {
                $sql[] = self::raw_fragment($order['sql']);
                $values = [...$values, ...$order['bindings']];
            } elseif ('random' === $order['type']) {
                $sql[] = $this->connection()->random_function();
            } else {
                $sql[] = $this->column($order['column'], $state) . ' ' . $order['direction'];
            }
        }

        return [] === $sql ? null : ['sql' => implode(', ', $sql), 'values' => $values];
    }

    /**
     * The joins of the builder, in order, as SQL separated by spaces, and the values of their bind markers
     * in order. A join held as SQL (relationships, a JOIN fragment) is sent as it is (see raw_fragment()). A
     * join of join() and its family is its type, JOIN, its table reference (see table_reference()), and the ON of its
     * conditions, which name their columns by the one rule (see column()) and are rendered as those of the
     * WHERE are; a CROSS join has no ON.
     *
     * @return array{sql: string, values: list<mixed>}
     */
    private function joins_clause(QueryState $state): array
    {
        $sql = [];
        $values = [];

        foreach ($state->joins as $join) {
            if (is_string($join)) {
                $sql[] = self::raw_fragment($join);
                continue;
            }

            $clause = "{$join['type']} JOIN " . $this->table_reference($join['table'], $join['alias'], 'join')['sql'];

            if ([] !== $join['wheres']) {
                $on = $this->conditions($state)->compile($join['wheres']);
                $clause .= " ON {$on['sql']}";
                $values = [...$values, ...$on['values']];
            }

            $sql[] = $clause;
        }

        return ['sql' => implode(' ', $sql), 'values' => $values];
    }

    /**
     * The compiler of the builder's conditions (see ConditionCompiler), which names their columns by the one
     * rule (see column()) as the statement of $state reads them.
     */
    private function conditions(QueryState $state): ConditionCompiler
    {
        return new ConditionCompiler($this->table, $this->connection(), fn(string $name): array => $this->column_reference($name, $state));
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
     * The FROM of the builder: from_raw()'s as written (see raw_fragment()), else the table reference of from()
     * (see table_reference()), else the model's table.
     */
    private function from_clause(QueryState $state): string
    {
        if (null !== $state->from_raw) {
            return self::raw_fragment($state->from_raw);
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
        return [] !== self::held_fields($state, ['legacy']);
    }

    /**
     * The fields of a state, other than those of $except, that are not as in a new state: the clauses it holds, in the
     * order of the fields. Legacy options that are an empty array hold no option, and are none. The fields of a new
     * state are read once: they are scalars, arrays and null, which no state shares by reference.
     *
     * @internal Serves QueryCompiler and Query (the clauses a closure group holds); not a supported API.
     * @param list<string> $except
     * @return list<string>
     */
    public static function held_fields(QueryState $state, array $except): array
    {
        static $new = null;
        $new ??= get_object_vars(new QueryState());
        $held = [];

        foreach (get_object_vars($state) as $field => $value) {
            if ($value !== $new[$field] && !in_array($field, $except, true) && !('legacy' === $field && [] === $value)) {
                $held[] = $field;
            }
        }

        return $held;
    }

    /**
     * $names as a sentence lists them, $conjunction before the last: "a", "a or b", "a, b or c".
     *
     * @param non-empty-list<string> $names
     */
    private static function listing(array $names, string $conjunction): string
    {
        $last = array_pop($names);

        return [] === $names ? $last : implode(', ', $names) . " $conjunction $last";
    }

    /**
     * Whether $clauses, the groups or the orders of a state, hold SQL written by hand (group_by_raw(),
     * order_by_raw()), which can name the select by position or expression.
     *
     * @param list<GroupBy>|list<OrderBy> $clauses
     */
    private static function has_raw(array $clauses): bool
    {
        foreach ($clauses as $clause) {
            if ('raw' === $clause['type']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $selects, what a state selects, holds nothing but plain columns without an alias (as select()
     * and add_select() record them): no SQL of select_raw(), no "name as alias". Such a column is only ever
     * identifiers (see column()), so it carries no alias another clause could name, and no aggregate. An
     * empty select is plain too.
     *
     * @param list<array{column: string, alias: string|null}|array{raw: string, bindings: list<mixed>}> $selects
     */
    private static function plain_columns(array $selects): bool
    {
        foreach ($selects as $select) {
            if (array_key_exists('raw', $select) || null !== $select['alias']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the WHERE of the conditions $wheres, rendered as $sql, is in parentheses already, as one
     * group: that of a closure, not negated (see ConditionCompiler's group()), or (0), which
     * ConditionCompiler::compile() writes for the lone condition '0'. An OR in it then never widens the
     * engine's conditions it is ANDed with (see where_clause()).
     *
     * @param list<Where> $wheres
     */
    private static function enclosed(array $wheres, string $sql): bool
    {
        if ('(0)' === $sql) {
            return true;
        }

        $where = 1 === count($wheres) ? $wheres[0] : null;

        return null !== $where && 'group' === $where['type'] && !$where['not'];
    }

    /**
     * The connection that checks the pk values of a finder (see pk_value_for_column()): the one $connection
     * returns when it is given, else the Table's.
     *
     * @param (Closure(): Connection)|null $connection
     */
    private static function pk_connection(Table $table, ?Closure $connection): Connection
    {
        return null === $connection ? $table->connection() : $connection();
    }

    /**
     * The WHERE of the 'conditions' of legacy options, if they have any: a string; a list, a
     * condition and its values as SQLBuilder::where() takes them; or a hash, whose names are read
     * through the model's aliases (#144), unless the options give the names to map as
     * 'mapped_names' (an empty list maps no alias), and are then named as the columns (#92).
     *
     * The SQL of a string or a list is refused, before any statement, when it holds a statement terminator (see
     * refuse_terminated_conditions()), for every legacy entry that reads conditions here: the finders, count(), exists(),
     * update_all() and delete_all(), and the loads of a relationship, whose declared conditions they are given.
     *
     * @param array<string, mixed> $options
     * @throws ActiveRecordException for SQL of the conditions that holds a statement terminator
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

            $this->refuse_terminated_conditions($options);
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
     * The clauses of an UPDATE or a DELETE, of the write $method: of a legacy write, its conditions, read as a SELECT
     * reads them (see apply_conditions()), its limit and its order, the only options a write reads; of the builder,
     * its WHERE, its ORDER BY (and their values) and its LIMIT, rendered as those of a SELECT are, by the same rules
     * (see where_clause() and order_clause()). Where the adapter has no LIMIT on UPDATE and DELETE (Postgres), a limit
     * picks the rows by the columns of the primary key in a subquery (#92), or by the adapter's row identity without one.
     *
     * SQLBuilder writes a WHERE and an ORDER BY only when its text is truthy in PHP: one of the builder that compiled to
     * no condition or no order would be left out, and the write would touch every row, or other rows. The builder
     * records none (raw SQL that is blank is refused at the call, see refuse_blank_sql(), and ConditionCompiler writes a
     * lone "0" as "(0)"); this is the last guard, before any statement.
     *
     * @throws ActiveRecordException for a state that holds legacy options and builder clauses both, or a WHERE or an
     *   ORDER BY of the builder that compiles to nothing (see refuse_lost_clause())
     */
    private function apply_write_clauses(SQLBuilder $sql, QueryState $state, string $method): void
    {
        if (null !== $state->legacy) {
            if (self::holds_clauses($state)) {
                throw new ActiveRecordException(sprintf('A query on %s holds both legacy options and builder clauses: it compiles the one or the other', $this->table->class->name));
            }

            $options = $state->legacy;
            $this->apply_conditions($sql, $options);

            if (isset($options['limit'])) {
                $sql->limit($options['limit']);
            }

            if (isset($options['order'])) {
                $sql->order($options['order']);
            }
        } else {
            $where = $this->where_clause($state);

            if (null !== $where) {
                $this->refuse_lost_clause('WHERE', $where['sql'], $method);
                $sql->where_condition($where['sql'], $where['values']);
            }

            $order = $this->order_clause($state);

            if (null !== $order) {
                $this->refuse_lost_clause('ORDER BY', $order['sql'], $method);
                $sql->order($order['sql']);
                $sql->order_values($order['values']);
            }

            if (null !== $state->limit) {
                $sql->limit($state->limit);
            }
        }

        $sql->key_columns(array_map($this->table->real_column_name(...), $this->table->pk));
    }

    /**
     * Refuses the legacy conditions of $options, a list (a string comes as a list of one), when their SQL, the first
     * element, holds a statement terminator (see refuse_statement_terminator()). They were sent as given, and MySQL,
     * MariaDB and SQLite ended the statement there: a limited update_all() or delete_all() wrote every row it matched,
     * and a find read every row. Their values are bound, and a hash names columns, so neither is read.
     *
     * The WHERE that Table::update() and Table::delete() are given, which they mark 'refuse_terminator' => false, is
     * sent as it always was: save() and Model::delete() give a pk hash, and a direct caller its own SQL. So are the
     * other legacy options (select, joins, from, group, having, order), which this does not read.
     *
     * @param array<string, mixed> $options
     * @throws ActiveRecordException
     */
    private function refuse_terminated_conditions(array $options): void
    {
        $conditions = $options['conditions'];

        if (false === ($options['refuse_terminator'] ?? true) || !is_array($conditions) || [] === $conditions) {
            return;
        }

        // SQLBuilder::where() takes the first element as the SQL, a string or an object that renders one
        $sql = $conditions[array_key_first($conditions)];

        if (is_string($sql) || $sql instanceof \Stringable) {
            $this->refuse_statement_terminator((string) $sql, "the 'conditions' option");
        }
    }

    /**
     * Refuses the write $method of a state whose where calls all added no condition (see QueryState::$empty_where):
     * where([]), a closure that adds none, where_null([]), or a when() or an unless() that ran neither its callback nor a
     * default (QueryState::$skipped_when). Its WHERE is empty, so it would write every row, where the calls meant it to
     * write by conditions, those of a filter that came empty for instance: where($filters) with no filter, when($ids, ...)
     * with no id. A write of every row calls no where method, Author::new_query()->delete(), and a read of such a query
     * is that of every row, as spec §5.2 has it.
     *
     * @throws ActiveRecordException
     */
    private function refuse_empty_where(QueryState $state, string $method): void
    {
        if (($state->empty_where || $state->skipped_when) && [] === $state->wheres) {
            $model = $this->table->class->name;

            throw new ActiveRecordException(sprintf(
                '%s() on %s has where() calls whose conditions all turned out empty (an empty array or list, a closure that adds none), or a when() or unless() that skipped its callback, so it would write every row: it is refused. A write of every row calls no where(): %s::new_query()->%s',
                $method,
                $model,
                $model,
                'update' === $method ? 'update(...)' : "$method()",
            ));
        }
    }

    /**
     * Refuses the WHERE or the ORDER BY ($clause) of the write $method, compiled to $sql, when it is no condition or no
     * order that SQLBuilder writes: empty or only whitespace, or "0" (see apply_write_clauses()).
     *
     * @throws ActiveRecordException
     */
    private function refuse_lost_clause(string $clause, string $sql, string $method): void
    {
        if ('' === trim($sql) || '0' === $sql) {
            throw new ActiveRecordException(sprintf("The %s of %s() on %s compiles to '%s', which is no %s: the statement is not sent", $clause, $method, $this->table->class->name, $sql, 'WHERE' === $clause ? 'condition' : 'order'));
        }
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
     * Whether the columns that a query groups by, $grouped (their real names as the keys), hold every column of the model's
     * primary key (Table::$pk). Each column of the key is read by the one rule of model_column(), as the groups are, so that an
     * attribute name or an alias of it counts as the column. A table without a primary key has none to cover, and neither does
     * one whose key names no column of the model: they are never covered. The key is the model's, which is the table's own
     * unless the model declares another: one that the database does not know for a key leaves the statement to it, which may
     * refuse it.
     *
     * @param array<string, true> $grouped
     */
    private function covers_primary_key(array $grouped): bool
    {
        if ([] === $this->table->pk) {
            return false;
        }

        foreach ($this->table->pk as $name) {
            $column = $this->model_column($name);

            if (null === $column || !isset($grouped[$column->name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * column()'s reading of $name: the SQL that names it, and the name its values are prepared by (see
     * value()). That is the real name of the column of the model an unqualified name stands for (see
     * model_column()), a pre-quoted one included, whatever case it differs by; any other name is taken as
     * written, and names no column of the model.
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
        $column = $quoted ? $name : $this->named_column($name);
        $sql = $connection->quote_name($column);
        // the values are prepared by the column the name stands for, matched as the database matches names:
        // 'some_date' is the DATE column some_Date on MySQL too, where Table maps no attribute name to a column
        // that differs from it by case only (Table::real_column_name()). Else by the name, the text of a
        // pre-quoted identifier, in which a doubled quote character is one
        $q = $connection::$QUOTE_CHARACTER;
        $data = $this->model_column($name)->name ?? ($quoted ? str_replace($q . $q, $q, substr($name, 1, -1)) : $column);

        if ([] === $state->joins || null !== $state->from_raw || !$this->names_model_column($column)) {
            return ['sql' => $sql, 'column' => $data];
        }

        return ['sql' => $this->rows_name($state) . '.' . $sql, 'column' => $data];
    }

    /**
     * The SQL that names the column $name stands for, qualified as the statement qualifies a column of the model with
     * joins (see column()): a column of the model (see model_column()) by the name the statement reads the model's rows
     * under and its real name, and any other name quoted part by part as written. Two names that give the same SQL name
     * one column: 'qty', 'measurements.qty' and an alias of qty alike.
     */
    private function qualified_reference(string $name, QueryState $state): string
    {
        $column = $this->model_column($name);

        return null === $column ? $this->quote_parts($name) : $this->rows_name($state) . '.' . $this->connection()->quote_name($column->name);
    }

    /**
     * The column an unqualified, unquoted name stands for: the column of the attribute an alias names, or of
     * an attribute name (#92), or else the name itself (see Table::real_column_name()).
     */
    private function named_column(string $name): string
    {
        return $this->table->real_column_name($this->alias_attributes()[$name] ?? $name);
    }

    /**
     * The name a statement gives the column it selects by $name (see column()): the text of the name, that
     * of its last part for a qualified one, and for an unqualified one the column an alias or an attribute
     * name stands for.
     */
    private function result_name(string $name): string
    {
        $parts = $this->name_parts($name);

        if (null === $parts) {
            return $name;
        }

        [$part, $quoted] = $parts[count($parts) - 1];

        if (!$quoted) {
            return 1 === count($parts) ? $this->named_column($part) : $part;
        }

        // the text of a quoted identifier, in which a doubled quote character is one
        $connection = $this->connection();
        $q = $connection::$QUOTE_CHARACTER;

        return str_replace($q . $q, $q, substr($part, 1, -1));
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
