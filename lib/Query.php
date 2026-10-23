<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

use Closure;

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
 * @phpstan-import-type Where from QueryState
 * @phpstan-import-type WhereBasic from QueryState
 * @phpstan-import-type WhereColumn from QueryState
 * @phpstan-import-type WhereRaw from QueryState
 * @phpstan-import-type WhereIn from QueryState
 * @phpstan-import-type WhereBetween from QueryState
 * @phpstan-import-type WhereBetweenColumns from QueryState
 * @phpstan-import-type WhereLike from QueryState
 */
class Query
{
    /**
     * The public builder methods that Model::__callStatic() forwards to a new query of the class it
     * is called on: Author::get() is Author::new_query()->get(). Each has a `@method static` tag in
     * the class docblock of Model, and each such tag a name here (QueryForwardingTest pins both).
     *
     * Not on it:
     * - update() and delete(): called statically they would write every row, so __callStatic()
     *   refuses them;
     * - the names that Model defines itself (find, first, last, all, count, exists, create, upsert,
     *   query, find_by_sql, update_all, delete_all, transaction, table, connection, ...): PHP calls
     *   them and they keep their legacy meaning, so they are reached through new_query() or a chain;
     * - the internals of the legacy entries (from_options, to_sql_builder, state, execute_*, legacy_*).
     *
     * @var list<string>
     */
    public const array FORWARDABLE = [
        'get',
        'select',
        'add_select',
        'select_raw',
        'distinct',
        'from',
        'from_raw',
        'where',
        'or_where',
        'where_not',
        'or_where_not',
        'where_column',
        'or_where_column',
        'where_raw',
        'or_where_raw',
        'where_in',
        'or_where_in',
        'where_not_in',
        'or_where_not_in',
        'where_null',
        'or_where_null',
        'where_not_null',
        'or_where_not_null',
        'where_between',
        'or_where_between',
        'where_not_between',
        'or_where_not_between',
        'where_between_columns',
        'or_where_between_columns',
        'where_not_between_columns',
        'or_where_not_between_columns',
        'where_like',
        'or_where_like',
        'where_not_like',
        'or_where_not_like',
        'where_all',
        'or_where_all',
        'where_any',
        'or_where_any',
        'where_none',
        'or_where_none',
        'where_key',
        'where_key_not',
        'to_sql',
        'get_bindings',
    ];

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
     * (see upsert()), and where the builder starts, Model::new_query(). It reads and builds through
     * $table itself, not through the Table the model class would load, which can be another instance.
     *
     * @internal Serves the legacy entries and Model::new_query(); not a supported API.
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
     * Selects these columns, in place of what the query selected: each argument a name, or an array of
     * names. Every clause of the builder reads a name by one rule (see QueryCompiler::column()): an
     * alias or an attribute name of the model names its column, with joins a column of the model gets
     * its table, and "table.column" and "table.*" are quoted as written. "name as alias" (AS in any
     * case, outside quotes: see QueryCompiler::aliased_column()) selects the column under that alias,
     * which names the attribute of the models. Called without columns, it selects what the query
     * selects by default again: every column, or with joins every column of the model's rows.
     *
     * <code>
     * Author::select('name', 'author_id as id')->get();
     * </code>
     *
     * @param string|array<mixed> ...$columns
     * @return $this
     * @throws ActiveRecordException for a name that is not a string, or an empty name or alias, and the
     *   query is left as it was
     */
    public function select(string|array ...$columns): static
    {
        $this->state->selects = $this->column_selects(__FUNCTION__, $columns);

        return $this;
    }

    /**
     * Selects these columns besides what the query selects, read as select() reads them. As in Eloquent,
     * a column the query already selects under the same alias is not selected twice, and on a query that
     * selects nothing yet they are all it selects.
     *
     * @param string|array<mixed> ...$columns
     * @return $this
     * @throws ActiveRecordException for a name that is not a string, or an empty name or alias, and the
     *   query is left as it was
     */
    public function add_select(string|array ...$columns): static
    {
        foreach ($this->column_selects(__FUNCTION__, $columns) as $select) {
            if (!in_array($select, $this->state->selects, true)) {
                $this->state->selects[] = $select;
            }
        }

        return $this;
    }

    /**
     * Selects an SQL expression besides what the query selects, sent as written, with the values of its
     * bind markers. A select() replaces it, its values included.
     *
     * <code>
     * Author::select('name')->select_raw('LENGTH(name) > ? AS long_name', [10])->get();
     * </code>
     *
     * @param array<mixed> $bindings
     * @return $this
     */
    public function select_raw(string $sql, array $bindings = []): static
    {
        $this->state->selects[] = ['raw' => $sql, 'bindings' => array_values($bindings)];

        return $this;
    }

    /**
     * Selects each row once (SELECT DISTINCT), or with false every row again.
     *
     * @return $this
     */
    public function distinct(bool $value = true): static
    {
        $this->state->distinct = $value;

        return $this;
    }

    /**
     * Reads the rows from $table, quoted part by part (database.table), under the alias $as if one is
     * given; without one, "table as alias" (AS in any case, outside quotes) gives one too (see
     * QueryCompiler::table_reference()). The rows are still read as the model's: with joins its columns
     * get the alias, else that table (see QueryCompiler::column()). It replaces a from_raw().
     *
     * <code>
     * Book::from('books', 'b')->select('b.name')->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException for an empty table name or alias, and the query is left as it was
     */
    public function from(string $table, ?string $as = null): static
    {
        // read, and refused if need be, at the call (spec §5.7)
        $reference = $this->compiler()->table_reference($table, $as, __FUNCTION__);

        $this->state->from = $reference['table'];
        $this->state->from_alias = $reference['alias'];
        $this->state->from_raw = null;
        $this->state->from_bindings = [];

        return $this;
    }

    /**
     * Reads the rows from a FROM written in SQL, a subquery for instance, sent as written, with the
     * values of its bind markers. It names its rows itself, so no column of the model gets a table then
     * (see QueryCompiler::column()). It replaces a from().
     *
     * <code>
     * Book::from_raw('(SELECT * FROM books WHERE book_id > ?) books', [10])->get();
     * </code>
     *
     * @param array<mixed> $bindings
     * @return $this
     */
    public function from_raw(string $sql, array $bindings = []): static
    {
        $this->state->from = null;
        $this->state->from_alias = null;
        $this->state->from_raw = $sql;
        $this->state->from_bindings = array_values($bindings);

        return $this;
    }

    /**
     * Adds a condition to the WHERE, ANDed with the conditions before it. Its forms:
     * - where('name', 'Tito'): the column equals the value;
     * - where('author_id', '>', 2): the column compared with the value by an operator, in any case:
     *   one of Laravel's (=, <, >, <=, >=, <>, !=, <=>, like, not like, ilike, ...: see OPERATORS), or
     *   one the adapter adds (sounds like on MySQL and MariaDB; @>, &&, is distinct from, ... on
     *   Postgres: see Connection::extra_operators());
     * - where('parent_author_id', null): IS NULL, and with != or <> IS NOT NULL;
     * - where(['name' => 'Tito', 'author_id' => [1, 2]]): a hash of conditions, read as the legacy
     *   finders read one: a list is IN, an empty list 1=0, a null in it IS NULL;
     * - where([['author_id', '>', 2], ['name', 'Tito']]): a list of conditions in the forms above,
     *   which can share the array with a hash;
     * - where(fn (Query $q) => $q->where(...)->or_where(...)): a group in parentheses of the conditions
     *   the closure adds to the query it is passed, a new query on the same model. A group without
     *   conditions is left out.
     *
     * The conditions of an array are ANDed one by one; or_where() and where_not() make them a group.
     * Every column is named by the one rule of the builder (see QueryCompiler::column()), and every
     * value is prepared for its column: a DateTime in the format of the column's type (DATE or DATETIME).
     *
     * <code>
     * Author::where('name', 'Tito')->where('author_id', '>', 1)->get();
     * </code>
     *
     * @param (Closure(self<TModel>): mixed)|string|array<mixed> $column
     * @return $this
     * @throws ActiveRecordException at the call, and the query is left as it was, for an unknown operator,
     *   a null compared by another operator than =, !=, <> and <=>, an array as the value, no value, an
     *   empty column name, a condition of a list in no known form, or a group that holds anything but
     *   where clauses
     */
    public function where(Closure|string|array $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->wheres(__FUNCTION__, 'AND', false, $column, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds a condition as where() does, ORed with the conditions before it.
     *
     * @param (Closure(self<TModel>): mixed)|string|array<mixed> $column
     * @return $this
     * @throws ActiveRecordException as where() does
     */
    public function or_where(Closure|string|array $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->wheres(__FUNCTION__, 'OR', false, $column, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds the negation of a condition in where()'s forms, ANDed: NOT (condition).
     *
     * <code>
     * Author::where_not('name', 'Tito')->get();
     * </code>
     *
     * @param (Closure(self<TModel>): mixed)|string|array<mixed> $column
     * @return $this
     * @throws ActiveRecordException as where() does
     */
    public function where_not(Closure|string|array $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->wheres(__FUNCTION__, 'AND', true, $column, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds the negation of a condition in where()'s forms, ORed: NOT (condition).
     *
     * @param (Closure(self<TModel>): mixed)|string|array<mixed> $column
     * @return $this
     * @throws ActiveRecordException as where() does
     */
    public function or_where_not(Closure|string|array $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->wheres(__FUNCTION__, 'OR', true, $column, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds a comparison of two columns, ANDed: where_column('author_id', 'parent_author_id') is
     * author_id = parent_author_id, and where_column('author_id', '>', 'parent_author_id') takes an
     * operator as where() does. Both names follow the rule of the builder (see QueryCompiler::column()).
     *
     * @return $this
     * @throws ActiveRecordException for an unknown operator, an empty column name, or no second column
     */
    public function where_column(string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->add_wheres([$this->column_where(__FUNCTION__, 'AND', $first, $operator, $second, func_num_args() - 1)]);
    }

    /**
     * Adds a comparison of two columns as where_column() does, ORed.
     *
     * @return $this
     * @throws ActiveRecordException as where_column() does
     */
    public function or_where_column(string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->add_wheres([$this->column_where(__FUNCTION__, 'OR', $first, $operator, $second, func_num_args() - 1)]);
    }

    /**
     * Adds a condition written in SQL, ANDed: sent as written, in parentheses when another condition is
     * beside it, with the values of its bind markers. As in a legacy positional condition, a value that
     * is a list is spread over its marker: where_raw('author_id IN(?)', [[1, 2]]) is author_id IN(?,?).
     *
     * <code>
     * Author::where_raw('LENGTH(name) > ?', [3])->get();
     * </code>
     *
     * @param array<mixed> $bindings
     * @return $this
     */
    public function where_raw(string $sql, array $bindings = []): static
    {
        return $this->add_wheres([$this->raw_where('AND', $sql, $bindings)]);
    }

    /**
     * Adds a condition written in SQL as where_raw() does, ORed.
     *
     * @param array<mixed> $bindings
     * @return $this
     */
    public function or_where_raw(string $sql, array $bindings = []): static
    {
        return $this->add_wheres([$this->raw_where('OR', $sql, $bindings)]);
    }

    /**
     * Adds column IN (values), ANDed, by the rules of a hash of conditions (spec §5.2): an empty list is
     * 1=0, which matches no row, and a null in the list is compared with IS NULL, (column IN(...) OR
     * column IS NULL). The keys of the list are not read.
     *
     * @param array<mixed> $values
     * @return $this
     */
    public function where_in(string $column, array $values): static
    {
        return $this->add_wheres([$this->in_where(__FUNCTION__, 'AND', $column, $values, false)]);
    }

    /**
     * Adds column IN (values) as where_in() does, ORed.
     *
     * @param array<mixed> $values
     * @return $this
     */
    public function or_where_in(string $column, array $values): static
    {
        return $this->add_wheres([$this->in_where(__FUNCTION__, 'OR', $column, $values, false)]);
    }

    /**
     * Adds column NOT IN (values), ANDed: an empty list is 1=1, which matches every row, and a null in the
     * list makes the column IS NOT NULL too, (column NOT IN(...) AND column IS NOT NULL), or with nulls
     * only, column IS NOT NULL.
     *
     * @param array<mixed> $values
     * @return $this
     */
    public function where_not_in(string $column, array $values): static
    {
        return $this->add_wheres([$this->in_where(__FUNCTION__, 'AND', $column, $values, true)]);
    }

    /**
     * Adds column NOT IN (values) as where_not_in() does, ORed.
     *
     * @param array<mixed> $values
     * @return $this
     */
    public function or_where_not_in(string $column, array $values): static
    {
        return $this->add_wheres([$this->in_where(__FUNCTION__, 'OR', $column, $values, true)]);
    }

    /**
     * Adds column IS NULL for a column, or for each column of a list, ANDed.
     *
     * @param string|array<mixed> $columns
     * @return $this
     */
    public function where_null(string|array $columns): static
    {
        return $this->add_wheres($this->null_wheres(__FUNCTION__, 'AND', $columns, false));
    }

    /**
     * Adds column IS NULL for a column, or for each column of a list, ORed.
     *
     * @param string|array<mixed> $columns
     * @return $this
     */
    public function or_where_null(string|array $columns): static
    {
        return $this->add_wheres($this->null_wheres(__FUNCTION__, 'OR', $columns, false));
    }

    /**
     * Adds column IS NOT NULL for a column, or for each column of a list, ANDed.
     *
     * @param string|array<mixed> $columns
     * @return $this
     */
    public function where_not_null(string|array $columns): static
    {
        return $this->add_wheres($this->null_wheres(__FUNCTION__, 'AND', $columns, true));
    }

    /**
     * Adds column IS NOT NULL for a column, or for each column of a list, ORed.
     *
     * @param string|array<mixed> $columns
     * @return $this
     */
    public function or_where_not_null(string|array $columns): static
    {
        return $this->add_wheres($this->null_wheres(__FUNCTION__, 'OR', $columns, true));
    }

    /**
     * Adds column BETWEEN ? AND ?, ANDed, with the two values of the list, whose keys are not read.
     *
     * @param array<mixed> $values
     * @return $this
     * @throws ActiveRecordException for a list that does not hold two values
     */
    public function where_between(string $column, array $values): static
    {
        return $this->add_wheres([$this->between_where(__FUNCTION__, 'AND', $column, $values, false)]);
    }

    /**
     * Adds column BETWEEN ? AND ? as where_between() does, ORed.
     *
     * @param array<mixed> $values
     * @return $this
     * @throws ActiveRecordException as where_between() does
     */
    public function or_where_between(string $column, array $values): static
    {
        return $this->add_wheres([$this->between_where(__FUNCTION__, 'OR', $column, $values, false)]);
    }

    /**
     * Adds column NOT BETWEEN ? AND ?, ANDed, with the two values of the list.
     *
     * @param array<mixed> $values
     * @return $this
     * @throws ActiveRecordException as where_between() does
     */
    public function where_not_between(string $column, array $values): static
    {
        return $this->add_wheres([$this->between_where(__FUNCTION__, 'AND', $column, $values, true)]);
    }

    /**
     * Adds column NOT BETWEEN ? AND ?, ORed, with the two values of the list.
     *
     * @param array<mixed> $values
     * @return $this
     * @throws ActiveRecordException as where_between() does
     */
    public function or_where_not_between(string $column, array $values): static
    {
        return $this->add_wheres([$this->between_where(__FUNCTION__, 'OR', $column, $values, true)]);
    }

    /**
     * Adds column BETWEEN first AND second, ANDed, with the two columns of the list.
     *
     * <code>
     * Author::where_between_columns('author_id', ['parent_author_id', 'author_id'])->get();
     * </code>
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException for a list that does not hold two column names
     */
    public function where_between_columns(string $column, array $columns): static
    {
        return $this->add_wheres([$this->between_columns_where(__FUNCTION__, 'AND', $column, $columns, false)]);
    }

    /**
     * Adds column BETWEEN first AND second as where_between_columns() does, ORed.
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException as where_between_columns() does
     */
    public function or_where_between_columns(string $column, array $columns): static
    {
        return $this->add_wheres([$this->between_columns_where(__FUNCTION__, 'OR', $column, $columns, false)]);
    }

    /**
     * Adds column NOT BETWEEN first AND second, ANDed, with the two columns of the list.
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException as where_between_columns() does
     */
    public function where_not_between_columns(string $column, array $columns): static
    {
        return $this->add_wheres([$this->between_columns_where(__FUNCTION__, 'AND', $column, $columns, true)]);
    }

    /**
     * Adds column NOT BETWEEN first AND second, ORed, with the two columns of the list.
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException as where_between_columns() does
     */
    public function or_where_not_between_columns(string $column, array $columns): static
    {
        return $this->add_wheres([$this->between_columns_where(__FUNCTION__, 'OR', $column, $columns, true)]);
    }

    /**
     * Adds column LIKE pattern, ANDed. The pattern takes the wildcards % (any characters) and _ (one
     * character), and every other character is itself. By default it matches in any case, as the
     * adapter can: LIKE on MySQL and MariaDB as the column's collation decides, ILIKE on Postgres,
     * LIKE on SQLite (ASCII letters only). With $case_sensitive it matches in the case given: LIKE
     * CAST(? AS BINARY) on MySQL and MariaDB, a byte comparison; LIKE on Postgres; GLOB on SQLite, with
     * the pattern translated (see Connection::like_condition() and like_pattern()).
     *
     * <code>
     * Author::where_like('name', 't%')->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException at the call, for a case-sensitive LIKE the adapter does not write
     */
    public function where_like(string $column, string $value, bool $case_sensitive = false): static
    {
        return $this->add_wheres([$this->like_where(__FUNCTION__, 'AND', $column, $value, $case_sensitive, false)]);
    }

    /**
     * Adds column LIKE pattern as where_like() does, ORed.
     *
     * @return $this
     * @throws ActiveRecordException as where_like() does
     */
    public function or_where_like(string $column, string $value, bool $case_sensitive = false): static
    {
        return $this->add_wheres([$this->like_where(__FUNCTION__, 'OR', $column, $value, $case_sensitive, false)]);
    }

    /**
     * Adds column NOT LIKE pattern, ANDed, the pattern as where_like() takes it.
     *
     * @return $this
     * @throws ActiveRecordException as where_like() does
     */
    public function where_not_like(string $column, string $value, bool $case_sensitive = false): static
    {
        return $this->add_wheres([$this->like_where(__FUNCTION__, 'AND', $column, $value, $case_sensitive, true)]);
    }

    /**
     * Adds column NOT LIKE pattern, ORed, the pattern as where_like() takes it.
     *
     * @return $this
     * @throws ActiveRecordException as where_like() does
     */
    public function or_where_not_like(string $column, string $value, bool $case_sensitive = false): static
    {
        return $this->add_wheres([$this->like_where(__FUNCTION__, 'OR', $column, $value, $case_sensitive, true)]);
    }

    /**
     * Adds a group in parentheses, ANDed, of the same comparison on each column, in where()'s forms,
     * joined with AND: where_all(['name', 'some_text'], 'like', 'T%') is (name LIKE ? AND some_text
     * LIKE ?). An empty list of columns is refused, where Laravel adds no condition: all, any or none of
     * no column has no meaning.
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException as where() does, and for an empty list of columns or a column name
     *   that is not a string
     */
    public function where_all(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->columns_wheres(__FUNCTION__, 'AND', 'AND', false, $columns, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds a group as where_all() does, ORed.
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException as where_all() does
     */
    public function or_where_all(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->columns_wheres(__FUNCTION__, 'OR', 'AND', false, $columns, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds a group in parentheses, ANDed, of the same comparison on each column, joined with OR:
     * where_any(['name', 'some_text'], 'Tito') is (name = ? OR some_text = ?).
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException as where_all() does
     */
    public function where_any(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->columns_wheres(__FUNCTION__, 'AND', 'OR', false, $columns, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds a group as where_any() does, ORed.
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException as where_all() does
     */
    public function or_where_any(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->columns_wheres(__FUNCTION__, 'OR', 'OR', false, $columns, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds the negation of where_any()'s group, ANDed: where_none(['name', 'some_text'], 'Tito') is
     * NOT (name = ? OR some_text = ?).
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException as where_all() does
     */
    public function where_none(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->columns_wheres(__FUNCTION__, 'AND', 'OR', true, $columns, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds the negation of where_any()'s group, ORed.
     *
     * @param array<mixed> $columns
     * @return $this
     * @throws ActiveRecordException as where_all() does
     */
    public function or_where_none(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_wheres($this->columns_wheres(__FUNCTION__, 'OR', 'OR', true, $columns, $operator, $value, func_num_args() - 1));
    }

    /**
     * Adds a condition on the primary key, ANDed: pk = id, or for a list pk IN (ids), by the rules of
     * where_in(). The primary key is named by its column, never through an alias, as the legacy finders
     * name it (spec §6.2.6), and with joins it gets the model's table.
     *
     * <code>
     * Author::where_key([1, 2])->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException for a model whose primary key is not one column
     */
    public function where_key(mixed $id): static
    {
        return $this->add_wheres([$this->key_where(__FUNCTION__, $id, false)]);
    }

    /**
     * Adds the opposite condition on the primary key, ANDed: pk != id, or for a list pk NOT IN (ids), by
     * the rules of where_not_in().
     *
     * @return $this
     * @throws ActiveRecordException as where_key() does
     */
    public function where_key_not(mixed $id): static
    {
        return $this->add_wheres([$this->key_where(__FUNCTION__, $id, true)]);
    }

    /**
     * The SELECT the query sends, with its bind markers (see get_bindings()).
     */
    public function to_sql(): string
    {
        return $this->to_sql_builder()->to_s();
    }

    /**
     * The values the query binds to the markers of its SELECT, in the order of the clauses: those of the
     * select, of the FROM, then of the WHERE. A value compared with a column is prepared for it (a
     * DateTime in the format of the column's type); the others, those of the *_raw() methods, are as they
     * were given, and the query prepares them when it runs (a DateTime is sent as a date and time).
     *
     * @return list<mixed>
     */
    public function get_bindings(): array
    {
        return $this->to_sql_builder()->select_bind_values();
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
        $models = $this->table()->find_by_sql($sql->to_s(), $sql->select_bind_values(), $readonly, $includes);

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
        $values = $table->format_date_bind_values($sql->select_bind_values());
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

    /**
     * The compiler of the statements of this query, which also reads what the builder methods are given
     * (column names, table references) at the call.
     */
    private function compiler(): QueryCompiler
    {
        return new QueryCompiler($this->table());
    }

    /**
     * The selects of the columns given to $method, select() or add_select(): each argument a name or an
     * array of names, each name parted from the alias that "name as alias" gives it (see
     * QueryCompiler::aliased_column()).
     *
     * @param array<string|array<mixed>> $columns
     * @return list<array{column: string, alias: string|null}>
     * @throws ActiveRecordException for a name that is not a string, or an empty name or alias
     */
    private function column_selects(string $method, array $columns): array
    {
        $compiler = $this->compiler();
        $selects = [];

        foreach ($columns as $column) {
            foreach (is_array($column) ? $column : [$column] as $name) {
                if (!is_string($name)) {
                    throw new ActiveRecordException(sprintf('A column name must be a string, not %s, in %s() on %s', get_debug_type($name), $method, $this->model_class));
                }

                $selects[] = $compiler->aliased_column($name, $method);
            }
        }

        return $selects;
    }

    /**
     * Adds conditions to the WHERE, after those it holds.
     *
     * @param list<Where> $wheres
     * @return $this
     */
    private function add_wheres(array $wheres): static
    {
        $this->state->wheres = [...$this->state->wheres, ...$wheres];

        return $this;
    }

    /**
     * The conditions of a call of $method, where() or one of its or_ and not forms (see where()),
     * joined to those before them by $boolean, and negated with $not. $count is the number of the
     * arguments given after the column.
     *
     * @param 'AND'|'OR' $boolean
     * @param Closure|string|array<mixed> $column
     * @return list<Where>
     * @throws ActiveRecordException
     */
    private function wheres(string $method, string $boolean, bool $not, Closure|string|array $column, mixed $operator, mixed $value, int $count): array
    {
        if ($column instanceof Closure) {
            return $this->group($boolean, $not, $this->closure_wheres($method, $column));
        }

        if (is_array($column)) {
            $wheres = $this->array_wheres($method, $column);

            // ANDed, they are as many conditions ANDed; with an OR or a NOT, one group
            return 'AND' === $boolean && !$not ? $wheres : $this->group($boolean, $not, $wheres);
        }

        $where = $this->basic_where($method, $boolean, $column, $operator, $value, $count);

        return $not ? $this->group($boolean, true, [$where]) : [$where];
    }

    /**
     * A group of conditions in parentheses, joined to those before it by $boolean and negated with
     * $not, as the one condition of a list; none for no conditions, since an empty group is left out.
     *
     * @param 'AND'|'OR' $boolean
     * @param list<Where> $wheres
     * @return list<Where>
     */
    private function group(string $boolean, bool $not, array $wheres): array
    {
        if ([] === $wheres) {
            return [];
        }

        $group = new QueryState();
        $group->wheres = $wheres;

        return [['type' => 'group', 'boolean' => $boolean, 'not' => $not, 'group' => $group]];
    }

    /**
     * The conditions a closure given to $method adds to the query it is passed: a new query on the
     * same model, which may hold nothing but where clauses (spec §5.2). They are taken when the closure
     * returns: a later change to that query changes nothing here.
     *
     * @return list<Where>
     * @throws ActiveRecordException for a query that holds another clause
     */
    private function closure_wheres(string $method, Closure $callback): array
    {
        $query = new self($this->model_class);
        $query->table = $this->table;
        $callback($query);

        $new = get_object_vars(new QueryState());
        $held = [];

        foreach (get_object_vars($query->state) as $field => $value) {
            if ('wheres' !== $field && $value !== $new[$field]) {
                $held[] = $field;
            }
        }

        if ([] !== $held) {
            throw new ActiveRecordException(sprintf('A where() group can only hold where clauses: the one given to %s() on %s also holds %s', $method, $this->model_class, implode(', ', $held)));
        }

        return $query->state->wheres;
    }

    /**
     * The conditions of an array given to $method: an entry with a string key is a condition of a hash,
     * column => value, which the compiler renders as the legacy finders render one (Expressions); any
     * other entry is a condition of a list, an array of where()'s arguments: [column, value] or [column,
     * operator, value], where the column may be a closure or a list too. They are ANDed.
     *
     * @param array<mixed> $conditions
     * @return list<Where>
     * @throws ActiveRecordException for an entry in no such form, or a condition that where() refuses
     */
    private function array_wheres(string $method, array $conditions): array
    {
        $wheres = [];

        foreach ($conditions as $key => $condition) {
            if (is_string($key)) {
                $wheres[] = ['type' => 'hash', 'boolean' => 'AND', 'column' => $this->column_name($method, $key), 'value' => $condition];
                continue;
            }

            if (!is_array($condition) || [] === $condition || count($condition) > 3) {
                throw new ActiveRecordException(sprintf(
                    'A condition of the list given to %s() on %s is [column, value] or [column, operator, value], not %s',
                    $method,
                    $this->model_class,
                    is_array($condition) ? sprintf('an array of %d', count($condition)) : get_debug_type($condition),
                ));
            }

            $condition = array_values($condition);
            $column = $condition[0];

            if (!$column instanceof Closure && !is_string($column) && !is_array($column)) {
                throw new ActiveRecordException(sprintf('A column name must be a string, not %s, in %s() on %s', get_debug_type($column), $method, $this->model_class));
            }

            $wheres = [...$wheres, ...$this->wheres($method, 'AND', false, $column, $condition[1] ?? null, $condition[2] ?? null, count($condition) - 1)];
        }

        return $wheres;
    }

    /**
     * A comparison of a column with a value (see where()).
     *
     * @param 'AND'|'OR' $boolean
     * @return WhereBasic
     * @throws ActiveRecordException as comparison() does, and for an empty column name
     */
    private function basic_where(string $method, string $boolean, string $column, mixed $operator, mixed $value, int $count): array
    {
        $column = $this->column_name($method, $column);
        [$operator, $value] = $this->comparison($method, $column, $operator, $value, $count);

        return ['type' => 'basic', 'boolean' => $boolean, 'column' => $column, 'operator' => $operator, 'value' => $value];
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
    private function comparison(string $method, string $column, mixed $operator, mixed $value, int $count): array
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
            throw new ActiveRecordException(sprintf("An array is no value for '%s' in %s() on %s: a list of values goes to where_in() or where_not_in()", $given, $method, $this->model_class));
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
            && (in_array($key, self::OPERATORS, true) || in_array($key, $this->table()->connection()->extra_operators(), true));

        if (!$known) {
            throw new ActiveRecordException(sprintf('Unknown operator %s in %s() on %s', is_string($operator) ? "'$operator'" : get_debug_type($operator), $method, $this->model_class));
        }

        return strtoupper($operator);
    }

    /**
     * A comparison of two columns (see where_column()), from the arguments given after the first column,
     * $count of them: one is the second column, compared with =.
     *
     * @param 'AND'|'OR' $boolean
     * @return WhereColumn
     * @throws ActiveRecordException for an unknown operator, an empty column name, or no second column
     */
    private function column_where(string $method, string $boolean, string $first, ?string $operator, ?string $second, int $count): array
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
     * A condition written in SQL (see where_raw()), its list values spread over their markers now (see
     * QueryCompiler::raw_condition()).
     *
     * @param 'AND'|'OR' $boolean
     * @param array<mixed> $bindings
     * @return WhereRaw
     */
    private function raw_where(string $boolean, string $sql, array $bindings): array
    {
        [$sql, $bindings] = $this->compiler()->raw_condition($sql, array_values($bindings));

        return ['type' => 'raw', 'boolean' => $boolean, 'sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * column IN (values), or with $not NOT IN (see where_in() and where_not_in()).
     *
     * @param 'AND'|'OR' $boolean
     * @param array<mixed> $values
     * @return WhereIn
     */
    private function in_where(string $method, string $boolean, string $column, array $values, bool $not): array
    {
        return ['type' => 'in', 'boolean' => $boolean, 'column' => $this->column_name($method, $column), 'values' => array_values($values), 'not' => $not];
    }

    /**
     * column IS NULL, or with $not IS NOT NULL, for a column or each column of a list.
     *
     * @param 'AND'|'OR' $boolean
     * @param string|array<mixed> $columns
     * @return list<Where>
     */
    private function null_wheres(string $method, string $boolean, string|array $columns, bool $not): array
    {
        $wheres = [];

        foreach ($this->column_list($method, is_array($columns) ? $columns : [$columns]) as $column) {
            $wheres[] = ['type' => 'null', 'boolean' => $boolean, 'column' => $column, 'not' => $not];
        }

        return $wheres;
    }

    /**
     * column BETWEEN two values, or with $not NOT BETWEEN.
     *
     * @param 'AND'|'OR' $boolean
     * @param array<mixed> $values
     * @return WhereBetween
     * @throws ActiveRecordException for a list that does not hold two values
     */
    private function between_where(string $method, string $boolean, string $column, array $values, bool $not): array
    {
        $column = $this->column_name($method, $column);
        $values = array_values($values);

        if (2 !== count($values)) {
            throw new ActiveRecordException(sprintf('%s() needs two values, not %d, on %s', $method, count($values), $this->model_class));
        }

        return ['type' => 'between', 'boolean' => $boolean, 'column' => $column, 'values' => [$values[0], $values[1]], 'not' => $not];
    }

    /**
     * column BETWEEN two columns, or with $not NOT BETWEEN.
     *
     * @param 'AND'|'OR' $boolean
     * @param array<mixed> $columns
     * @return WhereBetweenColumns
     * @throws ActiveRecordException for a list that does not hold two column names
     */
    private function between_columns_where(string $method, string $boolean, string $column, array $columns, bool $not): array
    {
        $column = $this->column_name($method, $column);

        if (2 !== count($columns)) {
            throw new ActiveRecordException(sprintf('%s() needs two values, not %d, on %s', $method, count($columns), $this->model_class));
        }

        [$first, $second] = $this->column_list($method, $columns);

        return ['type' => 'between_columns', 'boolean' => $boolean, 'column' => $column, 'columns' => [$first, $second], 'not' => $not];
    }

    /**
     * column LIKE pattern, or with $not NOT LIKE (see where_like()).
     *
     * @param 'AND'|'OR' $boolean
     * @return WhereLike
     * @throws ActiveRecordException for an empty column name, or a case-sensitive LIKE the adapter does not write
     */
    private function like_where(string $method, string $boolean, string $column, string $value, bool $case_sensitive, bool $not): array
    {
        $column = $this->column_name($method, $column);
        // the adapter refuses a LIKE it cannot write at the call (spec §5.7), not when the statement is compiled
        $this->table()->connection()->like_condition($column, $case_sensitive, $not);

        return ['type' => 'like', 'boolean' => $boolean, 'column' => $column, 'value' => $value, 'case_sensitive' => $case_sensitive, 'not' => $not];
    }

    /**
     * The group of where_all(), where_any() or where_none(): the same comparison (see where()) on each
     * column, joined with $glue, joined to the conditions before it by $boolean, and negated with $not.
     *
     * @param 'AND'|'OR' $boolean
     * @param 'AND'|'OR' $glue
     * @param array<mixed> $columns
     * @return list<Where>
     * @throws ActiveRecordException as comparison() does, for an empty list of columns, and for a column
     *   name that is not a string, or empty
     */
    private function columns_wheres(string $method, string $boolean, string $glue, bool $not, array $columns, mixed $operator, mixed $value, int $count): array
    {
        // all, any or none of no column has no meaning: Laravel's silent "no condition" would match every row
        if ([] === $columns) {
            throw new ActiveRecordException(sprintf('%s() needs at least one column, not an empty list, on %s', $method, $this->model_class));
        }

        $columns = $this->column_list($method, $columns);
        [$operator, $value] = $this->comparison($method, implode(', ', $columns), $operator, $value, $count);
        $wheres = [];

        foreach ($columns as $column) {
            $wheres[] = ['type' => 'basic', 'boolean' => $glue, 'column' => $column, 'operator' => $operator, 'value' => $value];
        }

        return $this->group($boolean, $not, $wheres);
    }

    /**
     * pk = id or pk IN (ids), or with $not != and NOT IN, ANDed. The pk is pre-quoted: the column itself,
     * which no alias names, and with joins the model's (see QueryCompiler::column()).
     *
     * @return WhereBasic|WhereIn
     * @throws ActiveRecordException for a model whose primary key is not one column
     */
    private function key_where(string $method, mixed $id, bool $not): array
    {
        $table = $this->table();

        if (1 !== count($table->pk)) {
            throw new ActiveRecordException(sprintf('%s() needs a single-column primary key; %s has %s', $method, $this->model_class, [] === $table->pk ? 'none' : 'a composite one'));
        }

        $column = $table->connection()->quote_name($table->real_column_name($table->pk[0]));

        if (is_array($id)) {
            return ['type' => 'in', 'boolean' => 'AND', 'column' => $column, 'values' => array_values($id), 'not' => $not];
        }

        return ['type' => 'basic', 'boolean' => 'AND', 'column' => $column, 'operator' => $not ? '!=' : '=', 'value' => $id];
    }

    /**
     * A column name given to $method (see QueryCompiler::column_name()).
     *
     * @throws ActiveRecordException for an empty name
     */
    private function column_name(string $method, string $name): string
    {
        return $this->compiler()->column_name($name, $method);
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
