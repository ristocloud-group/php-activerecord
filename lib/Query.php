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
 * through its terminals (get, first, find, value, pluck, exists, count, ...), which read the query
 * as it stands and leave it so. The legacy entries (find, count, exists, ...) hand it
 * their options array through from_options(). The writes of Table (insert, update, delete) and of
 * Model (update_all, delete_all) run through its execute_*() methods, and the upsert of Table through
 * upsert().
 *
 * @template TModel of Model
 * @package ActiveRecord
 * @phpstan-import-type Where from QueryState
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
     *   query, find_by_sql, update_all, delete_all, transaction, table, connection, readonly, ...): PHP
     *   calls them and they keep their legacy meaning, so they are reached through new_query() or a
     *   chain. PHP refuses a static call of an instance method of Model such as readonly() with its own
     *   Error, before __callStatic() sees it;
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
        'join',
        'left_join',
        'right_join',
        'cross_join',
        'joins',
        'left_joins',
        'order_by',
        'order_by_desc',
        'order_by_raw',
        'latest',
        'oldest',
        'reorder',
        'in_random_order',
        'group_by',
        'group_by_raw',
        'having',
        'or_having',
        'having_raw',
        'or_having_raw',
        'having_null',
        'having_not_null',
        'having_between',
        'limit',
        'take',
        'offset',
        'skip',
        'for_page',
        'with',
        'without',
        'with_only',
        'when',
        'unless',
        'tap',
        'clone',
        'to_sql',
        'to_raw_sql',
        'get_bindings',
        'first_or_fail',
        'first_or',
        'sole',
        'find_or_fail',
        'find_many',
        'find_or',
        'value',
        'sole_value',
        'pluck',
        'doesnt_exist',
        'min',
        'max',
        'sum',
        'avg',
        'average',
    ];

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
     * bind markers. A select() replaces it, its values included. SQL that holds -- or # is followed by a
     * newline, so that a line comment at its end cannot run on over the rest of the statement (see
     * QueryCompiler::raw_fragment()).
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
     * (see QueryCompiler::column()). It replaces a from(). SQL that holds -- or # is followed by a newline,
     * as select_raw() says.
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
     *   one of Laravel's (=, <, >, <=, >=, <>, !=, <=>, like, not like, ilike, ...: see Conditions), or
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
        return $this->add_wheres($this->wheres(__FUNCTION__, $column, $operator, $value, func_num_args() - 1, boolean: 'AND', not: false));
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
        return $this->add_wheres($this->wheres(__FUNCTION__, $column, $operator, $value, func_num_args() - 1, boolean: 'OR', not: false));
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
        return $this->add_wheres($this->wheres(__FUNCTION__, $column, $operator, $value, func_num_args() - 1, boolean: 'AND', not: true));
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
        return $this->add_wheres($this->wheres(__FUNCTION__, $column, $operator, $value, func_num_args() - 1, boolean: 'OR', not: true));
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
        return $this->add_wheres([$this->conditions()->column_comparison(__FUNCTION__, $first, $operator, $second, func_num_args() - 1, boolean: 'AND')]);
    }

    /**
     * Adds a comparison of two columns as where_column() does, ORed.
     *
     * @return $this
     * @throws ActiveRecordException as where_column() does
     */
    public function or_where_column(string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->add_wheres([$this->conditions()->column_comparison(__FUNCTION__, $first, $operator, $second, func_num_args() - 1, boolean: 'OR')]);
    }

    /**
     * Adds a condition written in SQL, ANDed: sent as written, in parentheses when another condition is
     * beside it, with the values of its bind markers. As in a legacy positional condition, a value that
     * is a list is spread over its marker: where_raw('author_id IN(?)', [[1, 2]]) is author_id IN(?,?).
     * The condition "0" is sent too, in parentheses, and no row meets it on MySQL, MariaDB and SQLite (Postgres
     * takes no integer for a condition, and refuses the statement). SQL that holds -- or # is followed by a
     * newline, as select_raw() says.
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
        return $this->add_wheres([$this->conditions()->raw($sql, $bindings, boolean: 'AND')]);
    }

    /**
     * Adds a condition written in SQL as where_raw() does, ORed.
     *
     * @param array<mixed> $bindings
     * @return $this
     */
    public function or_where_raw(string $sql, array $bindings = []): static
    {
        return $this->add_wheres([$this->conditions()->raw($sql, $bindings, boolean: 'OR')]);
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
        return $this->add_wheres([$this->conditions()->in(__FUNCTION__, $column, $values, boolean: 'AND', not: false)]);
    }

    /**
     * Adds column IN (values) as where_in() does, ORed.
     *
     * @param array<mixed> $values
     * @return $this
     */
    public function or_where_in(string $column, array $values): static
    {
        return $this->add_wheres([$this->conditions()->in(__FUNCTION__, $column, $values, boolean: 'OR', not: false)]);
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
        return $this->add_wheres([$this->conditions()->in(__FUNCTION__, $column, $values, boolean: 'AND', not: true)]);
    }

    /**
     * Adds column NOT IN (values) as where_not_in() does, ORed.
     *
     * @param array<mixed> $values
     * @return $this
     */
    public function or_where_not_in(string $column, array $values): static
    {
        return $this->add_wheres([$this->conditions()->in(__FUNCTION__, $column, $values, boolean: 'OR', not: true)]);
    }

    /**
     * Adds column IS NULL for a column, or for each column of a list, ANDed.
     *
     * @param string|array<mixed> $columns
     * @return $this
     */
    public function where_null(string|array $columns): static
    {
        return $this->add_wheres($this->conditions()->nulls(__FUNCTION__, $columns, boolean: 'AND', not: false));
    }

    /**
     * Adds column IS NULL for a column, or for each column of a list, ORed.
     *
     * @param string|array<mixed> $columns
     * @return $this
     */
    public function or_where_null(string|array $columns): static
    {
        return $this->add_wheres($this->conditions()->nulls(__FUNCTION__, $columns, boolean: 'OR', not: false));
    }

    /**
     * Adds column IS NOT NULL for a column, or for each column of a list, ANDed.
     *
     * @param string|array<mixed> $columns
     * @return $this
     */
    public function where_not_null(string|array $columns): static
    {
        return $this->add_wheres($this->conditions()->nulls(__FUNCTION__, $columns, boolean: 'AND', not: true));
    }

    /**
     * Adds column IS NOT NULL for a column, or for each column of a list, ORed.
     *
     * @param string|array<mixed> $columns
     * @return $this
     */
    public function or_where_not_null(string|array $columns): static
    {
        return $this->add_wheres($this->conditions()->nulls(__FUNCTION__, $columns, boolean: 'OR', not: true));
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
        return $this->add_wheres([$this->conditions()->between(__FUNCTION__, $column, $values, boolean: 'AND', not: false)]);
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
        return $this->add_wheres([$this->conditions()->between(__FUNCTION__, $column, $values, boolean: 'OR', not: false)]);
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
        return $this->add_wheres([$this->conditions()->between(__FUNCTION__, $column, $values, boolean: 'AND', not: true)]);
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
        return $this->add_wheres([$this->conditions()->between(__FUNCTION__, $column, $values, boolean: 'OR', not: true)]);
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
        return $this->add_wheres([$this->conditions()->between_columns(__FUNCTION__, $column, $columns, boolean: 'AND', not: false)]);
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
        return $this->add_wheres([$this->conditions()->between_columns(__FUNCTION__, $column, $columns, boolean: 'OR', not: false)]);
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
        return $this->add_wheres([$this->conditions()->between_columns(__FUNCTION__, $column, $columns, boolean: 'AND', not: true)]);
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
        return $this->add_wheres([$this->conditions()->between_columns(__FUNCTION__, $column, $columns, boolean: 'OR', not: true)]);
    }

    /**
     * Adds column LIKE pattern, ANDed. The pattern takes the wildcards % (any characters) and _ (one
     * character). By default it matches in any case, as the adapter can: LIKE on MySQL and MariaDB as
     * the column's collation decides, ILIKE on Postgres, LIKE on SQLite (ASCII letters only). With
     * $case_sensitive it matches in the case given:
     * - MySQL and MariaDB: LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_bin, which compares characters
     *   whatever the column's character set (latin1, utf8mb3, utf8mb4);
     * - Postgres: LIKE;
     * - SQLite: GLOB, with the pattern translated.
     * See Connection::like_condition() and like_pattern(). Postgres compares the column as text, so that
     * a LIKE takes a column of any type there too (see Connection::like_column()).
     *
     * A backslash escapes % and _ on MySQL, MariaDB and Postgres, whose LIKE has it as its escape
     * character, but not on SQLite, whose LIKE has none and whose GLOB brackets its metacharacters instead.
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
        return $this->add_wheres([$this->conditions()->like(__FUNCTION__, $column, $value, $case_sensitive, boolean: 'AND', not: false)]);
    }

    /**
     * Adds column LIKE pattern as where_like() does, ORed.
     *
     * @return $this
     * @throws ActiveRecordException as where_like() does
     */
    public function or_where_like(string $column, string $value, bool $case_sensitive = false): static
    {
        return $this->add_wheres([$this->conditions()->like(__FUNCTION__, $column, $value, $case_sensitive, boolean: 'OR', not: false)]);
    }

    /**
     * Adds column NOT LIKE pattern, ANDed, the pattern as where_like() takes it.
     *
     * @return $this
     * @throws ActiveRecordException as where_like() does
     */
    public function where_not_like(string $column, string $value, bool $case_sensitive = false): static
    {
        return $this->add_wheres([$this->conditions()->like(__FUNCTION__, $column, $value, $case_sensitive, boolean: 'AND', not: true)]);
    }

    /**
     * Adds column NOT LIKE pattern, ORed, the pattern as where_like() takes it.
     *
     * @return $this
     * @throws ActiveRecordException as where_like() does
     */
    public function or_where_not_like(string $column, string $value, bool $case_sensitive = false): static
    {
        return $this->add_wheres([$this->conditions()->like(__FUNCTION__, $column, $value, $case_sensitive, boolean: 'OR', not: true)]);
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
        return $this->add_wheres($this->conditions()->columns(__FUNCTION__, $columns, $operator, $value, func_num_args() - 1, boolean: 'AND', glue: 'AND', not: false));
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
        return $this->add_wheres($this->conditions()->columns(__FUNCTION__, $columns, $operator, $value, func_num_args() - 1, boolean: 'OR', glue: 'AND', not: false));
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
        return $this->add_wheres($this->conditions()->columns(__FUNCTION__, $columns, $operator, $value, func_num_args() - 1, boolean: 'AND', glue: 'OR', not: false));
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
        return $this->add_wheres($this->conditions()->columns(__FUNCTION__, $columns, $operator, $value, func_num_args() - 1, boolean: 'OR', glue: 'OR', not: false));
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
        return $this->add_wheres($this->conditions()->columns(__FUNCTION__, $columns, $operator, $value, func_num_args() - 1, boolean: 'AND', glue: 'OR', not: true));
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
        return $this->add_wheres($this->conditions()->columns(__FUNCTION__, $columns, $operator, $value, func_num_args() - 1, boolean: 'OR', glue: 'OR', not: true));
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
        return $this->add_wheres([$this->conditions()->key(__FUNCTION__, $id, not: false)]);
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
        return $this->add_wheres([$this->conditions()->key(__FUNCTION__, $id, not: true)]);
    }

    /**
     * Adds an INNER JOIN of $table, ON a comparison of two columns or ON the conditions a closure writes:
     * - join('authors', 'authors.author_id', '=', 'books.author_id'): the columns compared by an operator,
     *   any that where() takes; with two columns only, join('authors', 'authors.author_id',
     *   'books.author_id'), by =;
     * - join('authors', fn (JoinClause $join) => $join->on(...)->where(...)): the conditions the closure adds
     *   to the JoinClause it is passed, taken when it returns (see JoinClause).
     *
     * The table is read as from() reads its own: quoted part by part, and "table as alias" (AS in any case,
     * outside quotes) gives it an alias. The columns are named by the one rule of the builder (see
     * QueryCompiler::column()): with joins, a column of the model gets its table, and any other name is as
     * written. The joins are sent in the order they are added, those of joins() and left_joins() included,
     * and the values of their conditions are bound after those of the FROM and before those of the WHERE.
     *
     * <code>
     * Book::join('authors', 'authors.author_id', '=', 'books.author_id')->where('authors.name', 'Tito')->get();
     * </code>
     *
     * @param (Closure(JoinClause): mixed)|string $first
     * @return $this
     * @throws ActiveRecordException at the call, and the query is left as it was: for an empty table name or
     *   alias, for a condition that where() and where_column() refuse (an unknown operator, an empty column
     *   name, no second column, ...), and for a closure that adds no condition, since a join without one pairs
     *   every row with every row of the table (cross_join() joins without one)
     */
    public function join(string $table, Closure|string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->add_join(__FUNCTION__, 'INNER', $table, $first, $operator, $second, func_num_args() - 2);
    }

    /**
     * Adds a LEFT JOIN of $table, as join() adds an INNER JOIN: the rows of the model without a match are kept,
     * with nulls for the joined table's columns.
     *
     * @param (Closure(JoinClause): mixed)|string $first
     * @return $this
     * @throws ActiveRecordException as join() does
     */
    public function left_join(string $table, Closure|string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->add_join(__FUNCTION__, 'LEFT', $table, $first, $operator, $second, func_num_args() - 2);
    }

    /**
     * Adds a RIGHT JOIN of $table, as join() adds an INNER JOIN: the rows of the joined table without a match
     * are kept, with nulls for the model's columns. SQLite needs version 3.39 or later for a RIGHT JOIN: an
     * older one fails at the database.
     *
     * @param (Closure(JoinClause): mixed)|string $first
     * @return $this
     * @throws ActiveRecordException as join() does
     */
    public function right_join(string $table, Closure|string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->add_join(__FUNCTION__, 'RIGHT', $table, $first, $operator, $second, func_num_args() - 2);
    }

    /**
     * Adds a CROSS JOIN of $table, without an ON: every row paired with every row of the table. The table is
     * read as join() reads it.
     *
     * @return $this
     * @throws ActiveRecordException at the call, for an empty table name or alias, and the query is left as
     *   it was
     */
    public function cross_join(string $table): static
    {
        // read, and refused if need be, at the call (spec §5.7)
        $reference = $this->compiler()->table_reference($table, null, __FUNCTION__);
        $this->state->joins[] = ['type' => 'CROSS', 'table' => $reference['table'], 'alias' => $reference['alias'], 'wheres' => []];

        return $this;
    }

    /**
     * Joins the relationships of the model named in $relationships_or_sql, a name or a list of them, each
     * with the INNER JOIN of the legacy 'joins' option, and sends the entries that hold "JOIN " as written,
     * as that option does: hand-written JOIN fragments (one that holds -- or # followed by a newline, as
     * select_raw() says). When two relationships of one call join the same table, the second and later get
     * their relationship's name as alias. See Table::create_joins().
     *
     * Warning: an entry that holds "JOIN " (in any case) is sent to the database as raw SQL, and must never
     * come from user input. left_joins() takes relationship names only, and refuses a fragment.
     *
     * The join of a relationship is the legacy join SQL, unchanged, so it has the limits of the legacy
     * 'joins' option, and the database refuses the first three:
     * - it names the model's table, so it does not follow a from() alias;
     * - that alias is given among the relationships of one call only, so two relationships to the same table
     *   in separate calls are not aliased;
     * - a has_many relationship declared with 'through' is joined without its middle table;
     * - the declared 'conditions' of a relationship are not added to the ON clause, so the join pairs a row
     *   with the related rows that they would exclude (see left_joins()).
     *
     * <code>
     * Book::joins('author')->where('authors.name', 'Tito')->get();
     * Book::joins(['author', 'LEFT JOIN authors s ON(s.author_id = books.secondary_author_id)'])->get();
     * </code>
     *
     * @param string|array<mixed> $relationships_or_sql
     * @return $this
     * @throws RelationshipException at the call, for a name that is no relationship of the model, and the query
     *   is left as it was
     * @throws ActiveRecordException at the call, for an entry that is not a string
     */
    public function joins(string|array $relationships_or_sql): static
    {
        return $this->add_relationship_joins($this->relationship_names(__FUNCTION__, $relationships_or_sql), 'INNER');
    }

    /**
     * Joins the relationships of the model named in $relationships as joins() does, each with a LEFT JOIN:
     * the rows of the model without a related row are kept. It takes relationship names only: a JOIN
     * fragment belongs in joins(), and is refused here.
     *
     * Its joins are the legacy join SQL, unchanged, so they have the limits that joins() lists: they name the
     * model's table, so they do not follow a from() alias; two relationships to the same table in separate
     * calls are not aliased; and a has_many relationship declared with 'through' is joined without its middle
     * table. The database refuses each of these. The declared 'conditions' of a relationship are not added to
     * the ON clause either, which here changes which rows are kept: a row whose related rows they would all
     * exclude is joined to them, not kept without a match, so a where_null() on the joined table, to find the
     * rows without a match, keeps fewer rows.
     *
     * <code>
     * Event::left_joins('venue')->get();
     * </code>
     *
     * @param string|array<mixed> $relationships
     * @return $this
     * @throws RelationshipException at the call, for a name that is no relationship of the model, a JOIN
     *   fragment among them, and the query is left as it was
     * @throws ActiveRecordException at the call, for an entry that is not a string
     */
    public function left_joins(string|array $relationships): static
    {
        $names = $this->relationship_names(__FUNCTION__, $relationships);
        $table = $this->table();

        // each a declared relationship, which a JOIN fragment never is: it is refused with a message that
        // points to joins()
        foreach ($names as $name) {
            if (false !== stripos($name, 'JOIN ')) {
                throw new RelationshipException(sprintf("left_joins() on %s takes relationship names only, and '%s' is a JOIN fragment: a fragment belongs in joins()", $this->model_class, $name));
            }

            $table->get_relationship($name, true);
        }

        return $this->add_relationship_joins($names, 'LEFT');
    }

    /**
     * Orders the rows by a column, after the orders the query already has: ascending, or with $direction
     * descending. The direction is asc or desc in any case, and anything else is refused. The column is named by
     * the one rule of the builder (see QueryCompiler::column()): an alias or an attribute name of the model names
     * its column, and with joins a column of the model gets its table. It is one name, never SQL: to order by an
     * expression, write it with order_by_raw().
     *
     * <code>
     * Author::order_by('name')->order_by('author_id', 'desc')->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException at the call, and the query is left as it was, for a direction other than asc
     *   and desc, or an empty column name
     */
    public function order_by(string $column, string $direction = 'asc'): static
    {
        return $this->add_order(__FUNCTION__, $column, $direction);
    }

    /**
     * Orders the rows by a column, descending: order_by($column, 'desc').
     *
     * @return $this
     * @throws ActiveRecordException for an empty column name, and the query is left as it was
     */
    public function order_by_desc(string $column): static
    {
        return $this->add_order(__FUNCTION__, $column, 'desc');
    }

    /**
     * Orders the rows by an SQL expression, sent as written, with the values of its bind markers, among the other
     * orders in the order of the calls. Unlike where_raw() and having_raw(), it does not spread a value that is a
     * list over its marker, as select_raw() and from_raw() do not: write one marker for each value. SQL that holds
     * -- or # is followed by a newline, as select_raw() says.
     *
     * <code>
     * Author::order_by_raw('CASE WHEN parent_author_id = ? THEN 0 ELSE 1 END', [2])->get();
     * </code>
     *
     * @param array<mixed> $bindings
     * @return $this
     */
    public function order_by_raw(string $sql, array $bindings = []): static
    {
        $this->state->orders[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings)];

        return $this;
    }

    /**
     * Orders the rows by a column, the newest first: descending, by $column, which is created_at by default.
     *
     * <code>
     * Author::latest()->limit(5)->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException for an empty column name, and the query is left as it was
     */
    public function latest(string $column = 'created_at'): static
    {
        return $this->add_order(__FUNCTION__, $column, 'desc');
    }

    /**
     * Orders the rows by a column, the oldest first: ascending, by $column, which is created_at by default.
     *
     * @return $this
     * @throws ActiveRecordException for an empty column name, and the query is left as it was
     */
    public function oldest(string $column = 'created_at'): static
    {
        return $this->add_order(__FUNCTION__, $column, 'asc');
    }

    /**
     * Replaces every order the query has, those of order_by_raw() with their values and the random order
     * included, by the order of $column and $direction as order_by() reads them. Without a column it only
     * removes them. The orders added after it follow it.
     *
     * <code>
     * Author::order_by('name')->reorder('author_id', 'desc')->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException as order_by() does, for a direction no column goes with too, and then no
     *   order is removed
     */
    public function reorder(?string $column = null, string $direction = 'asc'): static
    {
        // read, and refused if need be, before any order is removed
        $direction = $this->order_direction(__FUNCTION__, $direction);

        $this->state->orders = null === $column ? [] : [$this->column_order(__FUNCTION__, $column, $direction)];

        return $this;
    }

    /**
     * Puts the rows in random order, after the orders the query already has: by the random function of the
     * adapter, RAND() on MySQL and MariaDB, RANDOM() on Postgres and SQLite (see Connection::random_function()).
     *
     * <code>
     * Author::in_random_order()->limit(1)->get();
     * </code>
     *
     * @return $this
     */
    public function in_random_order(): static
    {
        $this->state->orders[] = ['type' => 'random'];

        return $this;
    }

    /**
     * Groups the rows by these columns, after the groups the query already has. Each is one name, read by the one
     * rule of the builder (see QueryCompiler::column()), as order_by() reads its own: an expression is grouped by
     * with group_by_raw(). Without a column it adds none.
     *
     * <code>
     * Author::select('parent_author_id')->select_raw('COUNT(*) AS n')->group_by('parent_author_id')->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException for an empty column name, and then none of them is added
     */
    public function group_by(string ...$columns): static
    {
        $compiler = $this->compiler();
        $groups = [];

        foreach ($columns as $column) {
            $compiler->refuse_empty_column_name($column, __FUNCTION__);
            $groups[] = ['type' => 'column', 'column' => $column];
        }

        $this->state->groups = [...$this->state->groups, ...$groups];

        return $this;
    }

    /**
     * Groups the rows by an SQL expression, sent as written, with the values of its bind markers, after the groups
     * the query already has. A value that is a list is not spread over its marker, as in order_by_raw(): write one
     * marker for each value. SQL that holds -- or # is followed by a newline, as select_raw() says.
     *
     * @param array<mixed> $bindings
     * @return $this
     */
    public function group_by_raw(string $sql, array $bindings = []): static
    {
        $this->state->groups[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings)];

        return $this;
    }

    /**
     * Adds a condition to the HAVING, ANDed with the conditions before it: a column compared with a value by =,
     * having('parent_author_id', 2), or by an operator, having('parent_author_id', '>', 1), as where() reads them.
     * The condition is read and written as one of the WHERE is (see Conditions and ConditionCompiler), so the
     * operators are the same, an unknown one is refused, null is compared with IS NULL and IS NOT NULL, and a value
     * is prepared for its column. The column is named by the one rule of the builder: a name that is a column of
     * the model is its real column, and any other, the alias of a selected expression, is quoted as written (MySQL,
     * MariaDB and SQLite accept such an alias in a HAVING, Postgres does not). An aggregate is written with
     * having_raw().
     *
     * <code>
     * Author::select('parent_author_id')->group_by('parent_author_id')->having('parent_author_id', '>', 1)->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException at the call, and the query is left as it was, for an unknown operator, a null
     *   compared by another operator than =, !=, <> and <=>, an array as the value, no value, or an empty column
     *   name
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_havings([$this->conditions()->comparison(__FUNCTION__, $column, $operator, $value, func_num_args() - 1, boolean: 'AND')]);
    }

    /**
     * Adds a condition to the HAVING as having() does, ORed with the conditions before it.
     *
     * @return $this
     * @throws ActiveRecordException as having() does
     */
    public function or_having(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->add_havings([$this->conditions()->comparison(__FUNCTION__, $column, $operator, $value, func_num_args() - 1, boolean: 'OR')]);
    }

    /**
     * Adds a condition written in SQL to the HAVING, ANDed, sent as written, in parentheses when another condition
     * is beside it, with the values of its bind markers. As in where_raw(), a value that is a list is spread over
     * its marker: having_raw('parent_author_id IN(?)', [[1, 2]]) is parent_author_id IN(?,?), and the condition
     * "0" is sent, in parentheses. SQL that holds -- or # is followed by a newline, as select_raw() says.
     *
     * <code>
     * Author::select('parent_author_id')->group_by('parent_author_id')->having_raw('COUNT(*) > ?', [1])->get();
     * </code>
     *
     * @param array<mixed> $bindings
     * @return $this
     */
    public function having_raw(string $sql, array $bindings = []): static
    {
        return $this->add_havings([$this->conditions()->raw($sql, $bindings, boolean: 'AND')]);
    }

    /**
     * Adds a condition written in SQL to the HAVING as having_raw() does, ORed.
     *
     * @param array<mixed> $bindings
     * @return $this
     */
    public function or_having_raw(string $sql, array $bindings = []): static
    {
        return $this->add_havings([$this->conditions()->raw($sql, $bindings, boolean: 'OR')]);
    }

    /**
     * Adds column IS NULL to the HAVING, ANDed.
     *
     * @return $this
     * @throws ActiveRecordException for an empty column name
     */
    public function having_null(string $column): static
    {
        return $this->add_havings($this->conditions()->nulls(__FUNCTION__, $column, boolean: 'AND', not: false));
    }

    /**
     * Adds column IS NOT NULL to the HAVING, ANDed.
     *
     * @return $this
     * @throws ActiveRecordException for an empty column name
     */
    public function having_not_null(string $column): static
    {
        return $this->add_havings($this->conditions()->nulls(__FUNCTION__, $column, boolean: 'AND', not: true));
    }

    /**
     * Adds column BETWEEN ? AND ? to the HAVING, ANDed, with the two values of the list, whose keys are not read.
     *
     * @param array<mixed> $values
     * @return $this
     * @throws ActiveRecordException for an empty column name, or a list that does not hold two values, or holds
     *   an array
     */
    public function having_between(string $column, array $values): static
    {
        return $this->add_havings([$this->conditions()->between(__FUNCTION__, $column, $values, boolean: 'AND', not: false)]);
    }

    /**
     * Limits the rows to the first $value, in the way of the legacy 'limit' option (see SQLBuilder::limit()): 0 is
     * LIMIT 0 and finds nothing (#34). A later limit() or take(), or for_page(), replaces it.
     *
     * <code>
     * Author::order_by('name')->limit(10)->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException for a negative value, and the query is left as it was
     */
    public function limit(int $value): static
    {
        $this->state->limit = $this->window_value(__FUNCTION__, $value);

        return $this;
    }

    /**
     * Limits the rows to the first $value, as limit() does: Eloquent's other name for it.
     *
     * @return $this
     * @throws ActiveRecordException as limit() does
     */
    public function take(int $value): static
    {
        $this->state->limit = $this->window_value(__FUNCTION__, $value);

        return $this;
    }

    /**
     * Skips the first $value rows, in the way of the legacy 'offset' option (see SQLBuilder::offset()): without a
     * limit, every row after them is selected (#130). A later offset() or skip(), or for_page(), replaces it.
     *
     * <code>
     * Author::order_by('name')->offset(10)->limit(10)->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException for a negative value, and the query is left as it was
     */
    public function offset(int $value): static
    {
        $this->state->offset = $this->window_value(__FUNCTION__, $value);

        return $this;
    }

    /**
     * Skips the first $value rows, as offset() does: Eloquent's other name for it.
     *
     * @return $this
     * @throws ActiveRecordException as offset() does
     */
    public function skip(int $value): static
    {
        $this->state->offset = $this->window_value(__FUNCTION__, $value);

        return $this;
    }

    /**
     * Selects the rows of page $page (the first is 1) of pages of $per_page rows: it skips the rows of the pages
     * before it and limits the rows to one page, replacing the offset and the limit the query has. A page of 0
     * rows selects none. Without an order, the rows of the pages are the database's to choose.
     *
     * <code>
     * Author::order_by('author_id')->for_page(2, 25)->get();
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException at the call, and the query is left as it was, for a page before the first, a
     *   negative $per_page, or a page whose offset (the rows before it) is beyond the largest integer
     */
    public function for_page(int $page, int $per_page = 15): static
    {
        if ($page < 1) {
            throw new ActiveRecordException(sprintf('%s() needs a page >= 1, not %d, on %s', __FUNCTION__, $page, $this->model_class));
        }

        if ($per_page < 0) {
            throw new ActiveRecordException(sprintf('%s() needs a per_page >= 0, not %d, on %s', __FUNCTION__, $per_page, $this->model_class));
        }

        // the product would turn into a float beyond the largest integer
        if ($per_page > 0 && $page - 1 > intdiv(PHP_INT_MAX, $per_page)) {
            throw new ActiveRecordException(sprintf('%s() cannot start page %d of %d rows: the offset passes the largest integer, on %s', __FUNCTION__, $page, $per_page, $this->model_class));
        }

        $this->state->offset = ($page - 1) * $per_page;
        $this->state->limit = $per_page;

        return $this;
    }

    /**
     * Eager loads these relationships of the model with the models the query finds, as the legacy 'include' option
     * does: get(), first(), find() and the other terminals that return models send the statements that load them
     * through Table::find_by_sql(), after their own, and what is loaded is read with no query. A relationship is
     * named as the model declares it ($has_many, $has_one, $belongs_to), and one of a related model by dots:
     * 'author.books' loads the author of each model, and the books of those authors. A list of names is taken too,
     * in which a name may hold a list of its own, as the legacy option takes it: ['author' => ['books']].
     *
     * The relationships are merged into those the query loads already, each once, in the order they were first
     * given; one that others are loaded under is their parent, wherever it was first given. state()->includes holds
     * them as the legacy option takes them. A terminal that needs no model (count(), exists(), value(), pluck())
     * loads nothing.
     *
     * The relationships are loaded by the keys of the models found, so a select that leaves those columns out,
     * select('name')->with('books'), fails exactly as the legacy 'include' option does: a statement for a NULL key
     * is sent, PHP warns of the attribute the models lack, and an UndefinedPropertyException is thrown.
     *
     * <code>
     * $books = Book::with('author.books')->get();
     * </code>
     *
     * @param string|array<mixed> ...$relations
     * @return $this
     * @throws RelationshipException at the call, and the query is left as it was, for a name that is no relationship
     *   of its model, at any depth: where the legacy option throws it when it runs, and only if it finds a model
     * @throws ActiveRecordException at the call, and the query is left as it was, for an empty name ('author..books'),
     *   or a list that is no list of names: a constraint on a relationship (a Closure) is not read yet
     */
    public function with(string|array ...$relations): static
    {
        $paths = $this->include_tree()->relationship_paths(__FUNCTION__, $relations);
        $this->refuse_undeclared_relationships($paths);
        $this->state->includes = IncludeTree::including($this->state->includes, $paths);

        return $this;
    }

    /**
     * Takes relationships off those the query eager loads, each by its name, 'author.books' for the books under the
     * authors: what is loaded under a relationship goes with it, and a relationship that loses all it loaded under
     * it stays loaded. As in Eloquent, a name that the query does not load takes nothing off, and is not looked up:
     * it need not be a relationship of the model.
     *
     * <code>
     * Book::with('author.books')->without('author.books')->get(); // loads the authors only
     * </code>
     *
     * @return $this
     * @throws ActiveRecordException for an empty name, and the query is left as it was
     */
    public function without(string ...$relations): static
    {
        $includes = $this->state->includes;

        foreach ($this->include_tree()->relationship_paths(__FUNCTION__, $relations) as $path) {
            $includes = IncludeTree::excluding($includes, $path);
        }

        $this->state->includes = $includes;

        return $this;
    }

    /**
     * Eager loads only these relationships, as with() reads them: those the query loaded are replaced.
     *
     * @param string|array<mixed> ...$relations
     * @return $this
     * @throws RelationshipException as with() does
     * @throws ActiveRecordException as with() does
     */
    public function with_only(string|array ...$relations): static
    {
        $paths = $this->include_tree()->relationship_paths(__FUNCTION__, $relations);
        $this->refuse_undeclared_relationships($paths);
        $this->state->includes = IncludeTree::including([], $paths);

        return $this;
    }

    /**
     * Flags the models the query finds as readonly, as the legacy 'readonly' option does (see Model::readonly()):
     * they refuse save(), delete() and the other writes. The models loaded with them (see with()) are not; with
     * false none is.
     *
     * Model has an instance method of this name, which PHP calls and refuses to call without a model, so it is no
     * entry of the static forwarding: Author::readonly() is PHP's Error, and the call is made on a query,
     * Author::new_query()->readonly() or Author::where(...)->readonly().
     *
     * <code>
     * $author = Author::where('name', 'Tito')->readonly()->first();
     * </code>
     *
     * @return $this
     */
    public function readonly(bool $value = true): static
    {
        $this->state->readonly = $value;

        return $this;
    }

    /**
     * Calls $callback with the query and the value when $value is true as PHP's if reads it, or else $default, if
     * there is one, with the same arguments: Eloquent's when(). A value that is a Closure is called with the query
     * first, and what it returns is the value. Any other callable is no more than a value, and a true one.
     *
     * The query is mutable (spec §3.2), so the callback changes it, and it is what comes back: unless the callback
     * hands back another query on the model, as in Eloquent, which is then the query that goes on, a fork for
     * instance (see clone()). Anything else that the callback returns is of no use to the chain, and is left out.
     *
     * <code>
     * Author::when($name, fn (Query $query) => $query->where('name', $name))->get();
     * </code>
     *
     * @param callable(static, mixed): mixed $callback
     * @param (callable(static, mixed): mixed)|null $default
     * @return static
     */
    public function when(mixed $value, callable $callback, ?callable $default = null): static
    {
        return $this->conditional(true, $value, $callback, $default);
    }

    /**
     * Calls $callback with the query and the value when $value is false as PHP's if reads it, or else $default, if
     * there is one, with the same arguments: Eloquent's unless(), which is when() with the truth of the value
     * reversed. The rest is as when() has it.
     *
     * @param callable(static, mixed): mixed $callback
     * @param (callable(static, mixed): mixed)|null $default
     * @return static
     */
    public function unless(mixed $value, callable $callback, ?callable $default = null): static
    {
        return $this->conditional(false, $value, $callback, $default);
    }

    /**
     * Calls $callback with the query, and returns the query: a step in the chain that is no clause of its own.
     * What the callback returns is of no use here, not even another query, as in Eloquent's tap().
     *
     * <code>
     * Author::where('name', 'Tito')->tap(fn (Query $query) => error_log($query->to_raw_sql()))->get();
     * </code>
     *
     * @param callable(static): mixed $callback
     * @return $this
     */
    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * A copy of the query, to go its own way: a change to the copy never reaches the query it was made from, nor
     * the other way round, whatever it is made to (the conditions and the groups of them, the joins and their
     * conditions, the HAVING, the includes, the plain clauses). The query is mutable (spec §3.2), so this is how to
     * fork one. The model and its table are shared, and so are the values the query binds, which are not copied.
     *
     * <code>
     * $authors = Author::where('author_id', '>', 1);
     * $few = $authors->clone()->limit(2)->get();
     * $all = $authors->get();
     * </code>
     *
     * @return static
     */
    public function clone(): static
    {
        return clone $this;
    }

    /**
     * Makes PHP's own clone of a query the copy that clone() returns: its state is copied, down to the groups of
     * conditions in it.
     */
    public function __clone()
    {
        $this->state = clone $this->state;
    }

    /**
     * The SELECT the query sends, with its bind markers (see get_bindings()).
     */
    public function to_sql(): string
    {
        return $this->to_sql_builder()->to_s();
    }

    /**
     * The SELECT the query sends with its values written in it, each bind marker replaced, in order, by the next
     * value of get_bindings() as the adapter binds it: to read a statement, or to run it by hand, for debugging
     * only. The query itself always sends its values bound, never written in the statement.
     *
     * - A value that the adapter binds as an integer is written bare (see Connection::binds_as_integer()): on SQLite
     *   an int, and a bool, which turns into one.
     * - Every other value that is not null is the quoted text that is bound, a string through
     *   Connection::escape(): a string as it is; on MySQL, MariaDB and Postgres, which bind every value as text,
     *   an int as '5' and a bool as '1' or '0'; a float as PHP writes it (to the digits that the precision setting
     *   keeps; INF, -INF and NAN by name); a date and time as the query binds it (a date for a DATE column that it
     *   is compared with, else a date and time); an object that has a text (__toString()) as that text. No other
     *   object is a value.
     * - null is NULL.
     *
     * Written so, the statement runs by hand as the bound one does on every adapter: Postgres refuses a bare 1
     * against a boolean column, or a bare 5 against a text column, and takes '1' and '5' as it takes the text that
     * is bound; SQLite compares an integer bound as one with an integer expression, where the quoted text never
     * equals it.
     *
     * The markers are those PDO reads: a ? outside a string and a comment, so the ? of a string of SQL written
     * by hand is no marker, and ?? is a ? of the statement. What is written in place of a marker is never read
     * again: a ? in a value is the value's. A statement whose markers and values do not match is not written.
     *
     * <code>
     * Author::where('name', "O'Neil")->to_raw_sql(); // SELECT * FROM `authors` WHERE `name` = 'O\'Neil'
     * </code>
     *
     * @throws ActiveRecordException for markers and values that do not match, or a value that is no SQL
     */
    public function to_raw_sql(): string
    {
        return (new RawSqlWriter($this->table(), $this->model_class))->write($this->to_sql_builder());
    }

    /**
     * The values the query binds to the markers of its SELECT, in the order of the clauses: those of the
     * select, of the FROM, of the joins, of the WHERE, of the GROUP BY, of the HAVING, then of the ORDER BY. A
     * value compared with a column is prepared for it (a DateTime in the format of the column's type); the
     * others, those of the *_raw() methods, are as they were given, and the query prepares them when it runs (a
     * DateTime is sent as a date and time). The limit and the offset are written in the statement, not bound.
     *
     * @return list<mixed>
     */
    public function get_bindings(): array
    {
        return $this->to_sql_builder()->select_bind_values();
    }

    /**
     * Runs the query and returns the models of the rows it selects, through Table::find_by_sql().
     * The models are readonly if the query is (readonly(), or the legacy option of that name), and the
     * relationships it eager loads (with(), or the legacy 'include' option) are loaded with them.
     *
     * @return list<TModel>
     */
    public function get(): array
    {
        return $this->models($this->state);
    }

    /**
     * The model of the first row the query selects, or null when it selects none: the query with a LIMIT
     * of 1 and its offset, in its own order (without one, the database picks the row). A smaller limit of
     * the query's own stays: a query limited to no row has no first row.
     *
     * <code>
     * $author = Author::where('name', 'Tito')->order_by('author_id')->first();
     * </code>
     *
     * Model::first() is the legacy finder, which Author::first(...) calls.
     *
     * @return TModel|null
     */
    public function first(): ?Model
    {
        return $this->models($this->window(1))[0] ?? null;
    }

    /**
     * The model of the first row the query selects, read as first() reads it.
     *
     * @return TModel
     * @throws RecordNotFound when the query selects no row
     */
    public function first_or_fail(): Model
    {
        return $this->first() ?? throw new RecordNotFound(sprintf('%s() found no %s', __FUNCTION__, $this->model_class));
    }

    /**
     * The model of the first row the query selects, read as first() reads it, or else what $callback
     * returns, called without arguments.
     *
     * <code>
     * $author = Author::where('name', $name)->first_or(fn () => Author::create(['name' => $name]));
     * </code>
     *
     * @param callable(): mixed $callback
     * @return mixed The model (a TModel), or what $callback returns
     */
    public function first_or(callable $callback): mixed
    {
        return $this->first() ?? $callback();
    }

    /**
     * The model of the one row the query selects: the query with a LIMIT of 2 and its offset, which tells
     * one row from more (a smaller limit of the query's own stays, as in first()).
     *
     * @return TModel
     * @throws RecordNotFound when the query selects no row
     * @throws MultipleRecordsFound when it selects more than one
     */
    public function sole(): Model
    {
        return $this->sole_of(__FUNCTION__, $this->models($this->window(2)));
    }

    /**
     * The model of the row whose primary key is $id, or for a list of ids the models of the rows whose
     * primary key is one of them, among the rows the query selects: the key comes first in the WHERE, and
     * the query's own conditions follow in parentheses, so that an OR in them never widens the key (spec
     * §5.2). One id is read as first() reads a row, so null when it is not found. A list is read in the
     * query's order and window, the ids that are missing left out; an empty one needs no statement.
     *
     * The ids are read as the legacy finders by pk read theirs (QueryCompiler::pk_value_for_column() and
     * keep_integer_pk_values()): a DateTime for a DATE pk is its date, and an id that an INTEGER pk can
     * never equal ('abc', which Postgres refuses, aborting the transaction, #92) is looked up in no
     * statement, so found by none. The key is named by its column, never through an alias, and with joins
     * it gets the model's table.
     *
     * <code>
     * $author = Author::new_query()->find(1);
     * $others = Author::where('name', '!=', 'Tito')->find([1, 2, 3]);
     * </code>
     *
     * Model::find() is the legacy finder, which Author::find(...) calls: it throws RecordNotFound for an
     * id that is missing.
     *
     * @return ($id is array ? list<TModel> : TModel|null)
     * @throws ActiveRecordException before any statement, for a model whose primary key is not one column,
     *   or for a list that holds a list
     */
    public function find(mixed $id): Model|array|null
    {
        $models = $this->find_by_key(__FUNCTION__, $id)[0];

        return is_array($id) ? $models : ($models[0] ?? null);
    }

    /**
     * What find() finds for $id, which must find every id: else a RecordNotFound, with the message of the
     * legacy finders by pk (Model::find_by_pk()): "Couldn't find Author with ID=99" for one id, and for a
     * list of which some are missing "Couldn't find all Author with IDs (1,99) (found 1, but was looking
     * for 2)". An id given twice is looked for once (ids are told apart by their text in PHP, not by the
     * database's equality), and a row that a join repeats is found once, by its primary key.
     *
     * A select that leaves the primary key out lets the models only be counted: under a join that repeats
     * a row, the repeats can then make up for a missing id, which goes unnoticed.
     *
     * @return ($id is array ? list<TModel> : TModel)
     * @throws RecordNotFound when an id is missing
     * @throws ActiveRecordException as find() does
     */
    public function find_or_fail(mixed $id): Model|array
    {
        [$models, $ids] = $this->find_by_key(__FUNCTION__, $id);
        $missing = $this->missing($id, $models, $ids);

        if (null !== $missing) {
            throw new RecordNotFound($missing);
        }

        return is_array($id) ? $models : $models[0];
    }

    /**
     * The models of the rows whose primary key is one of $ids, read as find() reads a list.
     *
     * @param array<mixed> $ids
     * @return list<TModel>
     * @throws ActiveRecordException as find() does
     */
    public function find_many(array $ids): array
    {
        return $this->find_by_key(__FUNCTION__, $ids)[0];
    }

    /**
     * What find() finds for $id when it finds every id, as find_or_fail() requires, or else what $callback
     * returns, called without arguments. The ids are looked for as find_or_fail() looks for them, with the
     * same limit: a select that leaves the primary key out, under a join that repeats a row, can let a
     * missing id go unnoticed.
     *
     * @param callable(): mixed $callback
     * @return mixed The models (a TModel, or for a list of ids a list<TModel>), or what $callback returns
     * @throws ActiveRecordException as find() does
     */
    public function find_or(mixed $id, callable $callback): mixed
    {
        [$models, $ids] = $this->find_by_key(__FUNCTION__, $id);

        if (null !== $this->missing($id, $models, $ids)) {
            return $callback();
        }

        return is_array($id) ? $models : $models[0];
    }

    /**
     * The value of $column in the first row the query selects, read as first() reads it, or null when it
     * selects none. Only that column is selected, named by the one rule of the builder (see
     * QueryCompiler::column()). A column of the model is cast as a model casts its attribute (Column::cast():
     * an integer beyond PHP_INT_MAX is its exact digits, #44; a DECIMAL a float, #146; a date an
     * ActiveRecord\DateTime); any other name, a qualified one for instance, gives the value as the driver
     * returns it (see QueryCompiler::model_column()). No model is built.
     *
     * <code>
     * $name = Author::where('author_id', 1)->value('name');
     * </code>
     *
     * @throws ActiveRecordException for an empty column name, before any statement
     */
    public function value(string $column): mixed
    {
        $rows = $this->column_rows(__FUNCTION__, [$column], $this->window(1));

        return [] === $rows ? null : $this->caster($column)($rows[0][0]);
    }

    /**
     * The value of $column in the one row the query selects, read as sole() reads the row and value() the
     * value.
     *
     * @throws RecordNotFound when the query selects no row
     * @throws MultipleRecordsFound when it selects more than one
     * @throws ActiveRecordException for an empty column name, before any statement
     */
    public function sole_value(string $column): mixed
    {
        $row = $this->sole_of(__FUNCTION__, $this->column_rows(__FUNCTION__, [$column], $this->window(2)));

        return $this->caster($column)($row[0]);
    }

    /**
     * The values of $column in the rows the query selects, in order, each read as value() reads one. With
     * $key, a map of them instead, keyed by the value of $key in each row as the driver returns it, since a
     * cast one can be no key (a date is an object, a DECIMAL's float loses digits): PHP takes it for a key
     * as it takes any, except that a null is '' and a float its text. A later row replaces an earlier one
     * of the same key. No model is built.
     *
     * <code>
     * $names = Author::order_by('name')->pluck('name');
     * $names_by_id = Author::new_query()->pluck('name', 'author_id');
     * </code>
     *
     * @return array<mixed>
     * @throws ActiveRecordException for an empty column name, before any statement
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = $this->column_rows(__FUNCTION__, null === $key ? [$column] : [$column, $key], $this->state);
        $cast = $this->caster($column);
        $values = [];

        foreach ($rows as $row) {
            if (null === $key) {
                $values[] = $cast($row[0]);
            } else {
                $values[self::array_key($row[1])] = $cast($row[0]);
            }
        }

        return $values;
    }

    /**
     * Whether the query selects any row, without counting: the adapter's EXISTS() over its SELECT, which
     * stops at the first row. A query of the builder leaves its order, its limit and its offset out, as the
     * legacy exists does (spec §5.3), so a limit of 0 does not make it false. Without a GROUP BY and a
     * HAVING it selects 1 there, as the legacy exists does, so a select of an aggregate, one row even when
     * no row matches, does not make it true; with one, which may name an alias of the select, the select
     * stays (see QueryCompiler::compile_exists()). The statement that ran is left in Table::$last_sql.
     *
     * Model::exists() is the legacy exists, which Author::exists(...) calls.
     */
    public function exists(): bool
    {
        $table = $this->table();
        $conn = $table->connection();
        $sql = $this->compiler()->compile_exists($this->state);
        $values = $table->format_date_bind_values($sql->select_bind_values());
        $statement = $conn->exists_sql($sql->to_s());
        $table->last_sql = $statement;

        return (bool) (int) $conn->query_and_fetch_one($statement, $values);
    }

    /**
     * Whether the query selects no row: the opposite of exists().
     */
    public function doesnt_exist(): bool
    {
        return !$this->exists();
    }

    /**
     * The number of rows the query selects; with a $column, of those in which the column is not null, as
     * COUNT(column) counts. A query neither grouped, distinct nor windowed is counted in place, its order
     * left out. Any other is counted as a subquery, SELECT COUNT(*) FROM (…) AS t, so that a grouped query
     * counts its groups, a distinct one its distinct rows (with a $column and no select of its own, the
     * column's distinct values that are not null, as Eloquent's COUNT(DISTINCT column) counts them), and
     * limit() and offset() the rows of their window. The subquery keeps the query's select wherever a clause
     * or the rows may depend on it (an alias that a GROUP BY or an ORDER BY names, a column that SQL of
     * group_by_raw() or of a window's order_by_raw() names by position, an aggregate that makes one row of
     * the rows), and counting rows of plain columns without an alias selects 1 instead (see
     * QueryCompiler::compile_count()). The statement that ran is left in Table::$last_sql.
     *
     * On a grouped query, a counted $column must be grouped, or be what the query's own select gives:
     * MySQL's ONLY_FULL_GROUP_BY and Postgres refuse any other column beside a GROUP BY.
     *
     * <code>
     * $parents = Author::group_by('parent_author_id')->count();
     * </code>
     *
     * Model::count() is the legacy count, which Author::count(...) calls, unchanged: grouped, it counts the
     * rows of the first group.
     *
     * @throws ActiveRecordException for an empty column name, before any statement
     */
    public function count(string $column = '*'): int
    {
        $compiler = $this->compiler();
        $compiler->refuse_empty_column_name($column, __FUNCTION__);

        $table = $this->table();
        $sql = $compiler->compile_count($this->state, $column);
        $values = $table->format_date_bind_values($sql->select_bind_values());
        $row = $table->connection()->query(($table->last_sql = $sql->to_s()), $values)->fetch(\PDO::FETCH_NUM);

        return is_array($row) ? (int) $row[0] : 0;
    }

    /**
     * The smallest value of $column among the rows the query selects, as the column's type reads it (see
     * AggregateCaster): an int where the exact value of an integer column fits PHP_INT_MIN to PHP_INT_MAX, else its exact
     * digits as a string (a MySQL BIGINT UNSIGNED above PHP_INT_MAX, #44); a DECIMAL as the string the database returns,
     * its scale kept; a float column as a float. Null when no row has a value. A column that is no column of the model
     * (a joined table's, "authors.name"), and a column of any other type (text, a date), gives the driver's value as it is.
     *
     * The aggregate is that of the rows of the result, as count() counts them: a query that is grouped, distinct or
     * windowed is aggregated as a subquery (see QueryCompiler::compile_aggregate()), and then reads the column from the
     * query's select, so a select of its own must hold it. The statement that ran is left in Table::$last_sql.
     *
     * <code>
     * $first = Order::where('customer_id', 7)->min('placed_at');
     * </code>
     *
     * @throws ActiveRecordException for an empty column name, or *, before any statement
     */
    public function min(string $column): int|string|float|null
    {
        return $this->aggregate(__FUNCTION__, 'MIN', $column);
    }

    /**
     * The largest value of $column among the rows the query selects, read as min() reads its value.
     *
     * <code>
     * $highest = Measurement::where('label', 'a')->max('big');
     * </code>
     *
     * @throws ActiveRecordException as min() does
     */
    public function max(string $column): int|string|float|null
    {
        return $this->aggregate(__FUNCTION__, 'MAX', $column);
    }

    /**
     * The sum of $column over the rows the query selects, exact, and of the type the column has (see min()): an int
     * while the sum fits PHP_INT_MIN to PHP_INT_MAX and else its exact digits, which MySQL, MariaDB and Postgres sum into
     * a wider number; a DECIMAL as the exact string, its scale kept; a float for a float column. NULLs are not summed, and
     * the sum of no value is 0 of that type: 0, '0' or 0.0. A column that is no column of the model gives the driver's
     * value, which is null for no row.
     *
     * SQLite has no exact decimal (it stores a DECIMAL as a REAL), so there the sum of a DECIMAL column is a float, and a
     * sum of integers beyond PHP_INT_MAX is SQLite's own "integer overflow" error, a DatabaseException.
     *
     * <code>
     * $total = Order::where('customer_id', 7)->sum('total');
     * </code>
     *
     * @return int|string|float|null Null only for a column that is no column of the model, over no row
     * @throws ActiveRecordException as min() does
     */
    public function sum(string $column): int|string|float|null
    {
        return $this->aggregate(__FUNCTION__, 'SUM', $column);
    }

    /**
     * The average of $column over the rows the query selects, or null when no row has a value (NULLs are not counted).
     * The average of an integer or a DECIMAL column is exact: its SUM divided by its COUNT, in one statement
     * (SELECT SUM(col), COUNT(col)), by a long division in PHP rounded half up, at the scale of the SUM plus 4, as a string:
     * '1.6667' for the INT column of qty 1, 2, 2, and '617283945061.730000' for a DECIMAL(20,2). So it is the same on every
     * database, and exact beyond what a float keeps, where the databases' own AVG differ in scale and type (see
     * ExactDecimal). The average of a float column is the database's own, as a float. A column that is no column of
     * the model gives the driver's AVG as it is.
     *
     * SQLite has no exact decimal, so there the average of a DECIMAL column is a float.
     *
     * <code>
     * $mean = Measurement::where('label', 'a')->avg('qty');
     * </code>
     *
     * @throws ActiveRecordException as min() does
     */
    public function avg(string $column): string|float|null
    {
        return $this->average_of(__FUNCTION__, $column);
    }

    /**
     * The average of $column, as avg() reads it: Eloquent's other name for it.
     *
     * @throws ActiveRecordException as avg() does
     */
    public function average(string $column): string|float|null
    {
        return $this->average_of(__FUNCTION__, $column);
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
     * The models of the rows $state selects, through Table::find_by_sql(), with the engine's $conditions
     * first in the WHERE (see QueryCompiler::compile_select()). The readonly flag and the relationships to
     * eager load are those of the builder, or of the legacy options 'readonly' and 'include' (see get()): a
     * state holds the one or the other.
     *
     * @param list<Where> $conditions
     * @return list<TModel>
     */
    private function models(QueryState $state, array $conditions = []): array
    {
        $options = $state->legacy ?? [];
        $sql = $this->compiler()->compile_select($state, $conditions);
        $readonly = $state->readonly || (array_key_exists('readonly', $options) && $options['readonly']);
        // no includes at all is null, which find_by_sql() takes as nothing to load, and spares it the attributes
        $includes = array_key_exists('include', $options) ? $options['include'] : ([] === $state->includes ? null : $state->includes);

        /** @var list<TModel> $models */
        $models = $this->table()->find_by_sql($sql->to_s(), $sql->select_bind_values(), $readonly, $includes);

        return $models;
    }

    /**
     * A copy of the query's state that selects $rows rows at most: its own limit when it is smaller.
     */
    private function window(int $rows): QueryState
    {
        $state = clone $this->state;
        $state->limit = min($state->limit ?? $rows, $rows);

        return $state;
    }

    /**
     * The one of $rows, which $method read with a window of two rows.
     *
     * @template TRow
     * @param list<TRow> $rows
     * @return TRow
     * @throws RecordNotFound for no row
     * @throws MultipleRecordsFound for more than one
     */
    private function sole_of(string $method, array $rows): mixed
    {
        return match (count($rows)) {
            0 => throw new RecordNotFound(sprintf('%s() found no %s', $method, $this->model_class)),
            1 => $rows[0],
            default => throw new MultipleRecordsFound(sprintf('%s() found more than one %s', $method, $this->model_class)),
        };
    }

    /**
     * What a find by pk of $method, find() or one of its family, finds for $id (see find()): the models,
     * and the ids it looks for, each once, by the key of its value (see key_of()), as given.
     *
     * @return array{list<TModel>, array<mixed>}
     * @throws ActiveRecordException for a model whose primary key is not one column, or for a list that
     *   holds a list
     */
    private function find_by_key(string $method, mixed $id): array
    {
        $table = $this->table();

        if (1 !== count($table->pk)) {
            throw new ActiveRecordException(sprintf(
                '%s() needs a single-column primary key; %s has %s, for %s',
                $method,
                $this->model_class,
                [] === $table->pk ? 'none' : 'a composite one (' . implode(', ', $table->pk) . ')',
                is_array($id) ? 'the ids [' . implode(', ', array_map(self::describe_id(...), $id)) . ']' : 'the id ' . self::describe_id($id),
            ));
        }

        $pk = $table->pk[0];
        $given = is_array($id) ? array_values($id) : [$id];
        // the ids as the legacy finders by pk read theirs: the date of a DateTime for a DATE pk
        $values = array_map(fn(mixed $value): mixed => QueryCompiler::pk_value_for_column($table, $pk, $value), $given);
        $ids = [];

        foreach ($values as $i => $value) {
            $ids[$this->key_of($value)] ??= $given[$i];
        }

        // nothing to look for, or nothing an INTEGER pk can equal (Postgres, #92): no statement
        if ([] === $values || !QueryCompiler::keep_integer_pk_values($table, $pk, $values)) {
            return [[], $ids];
        }

        $key = $this->conditions()->key($method, is_array($id) ? $values : $values[0], not: false);

        return [$this->models(is_array($id) ? $this->state : $this->window(1), [$key]), $ids];
    }

    /**
     * The message of the RecordNotFound of a find of $id that found $models, looking for $ids (see
     * find_by_key()): that of the legacy finders by pk (Model::find_by_pk()); or null when no id is missing.
     *
     * @param list<TModel> $models
     * @param array<mixed> $ids
     */
    private function missing(mixed $id, array $models, array $ids): ?string
    {
        $looking_for = is_array($id) ? count($ids) : 1;
        $found = is_array($id) ? $this->rows_found($models) : count($models);

        if ($found >= $looking_for) {
            return null;
        }

        if (1 === $looking_for) {
            return sprintf("Couldn't find %s with ID=%s", $this->model_class, self::describe_id(is_array($id) ? reset($ids) : $id));
        }

        return sprintf("Couldn't find all %s with IDs (%s) (found %d, but was looking for %d)", $this->model_class, implode(',', array_map(self::describe_id(...), $ids)), $found, $looking_for);
    }

    /**
     * How many of the model's rows $models are, a find by pk found: one per value of the primary key, since
     * a join can repeat a row, or one per model when the query selected no primary key.
     *
     * @param list<TModel> $models
     */
    private function rows_found(array $models): int
    {
        $table = $this->table();
        $pk = $table->pk[0];
        // the attribute of the pk column, which a row is read into
        $column = $table->columns[$table->real_column_name($pk)] ?? null;
        $attribute = null === $column ? $pk : $column->inflected_name;
        $rows = [];

        foreach ($models as $model) {
            $attributes = $model->attributes();
            $rows[array_key_exists($attribute, $attributes) ? 'key ' . $this->key_of($attributes[$attribute]) : 'model ' . spl_object_id($model)] = true;
        }

        return count($rows);
    }

    /**
     * A text two ids share when they are one id: that of a scalar ('1' for 1 and '1'), and of a date and time
     * as the connection binds it.
     */
    private function key_of(mixed $value): string
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $this->table()->connection()->datetime_to_string($value),
            is_scalar($value), null === $value, $value instanceof \Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }

    /**
     * The values of $columns in the rows $state selects, in order, as the driver returns them: $state with
     * those columns as its select, each named by the one rule of the builder, for a call of $method. The
     * statement is left in Table::$last_sql.
     *
     * @param list<string> $columns
     * @return list<array<mixed>>
     * @throws ActiveRecordException for an empty column name
     */
    private function column_rows(string $method, array $columns, QueryState $state): array
    {
        $compiler = $this->compiler();
        $state = clone $state;
        $state->selects = [];

        foreach ($columns as $column) {
            $compiler->refuse_empty_column_name($column, $method);
            $state->selects[] = ['column' => $column, 'alias' => null];
        }

        $table = $this->table();
        $sql = $compiler->compile_select($state);
        $values = $table->format_date_bind_values($sql->select_bind_values());
        $statement = $table->connection()->query(($table->last_sql = $sql->to_s()), $values);
        $rows = [];

        while (is_array($row = $statement->fetch(\PDO::FETCH_NUM))) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * $function (MIN, MAX or SUM) of $column over the rows of the query, read by the kind of the column (see
     * AggregateCaster::cast()), for a call of $method.
     *
     * @param 'MIN'|'MAX'|'SUM' $function
     * @throws ActiveRecordException for an empty column name, or *
     */
    private function aggregate(string $method, string $function, string $column): mixed
    {
        $kind = $this->aggregate_kind($method, $column);

        return AggregateCaster::cast($kind, $function, $this->aggregate_row($column, [$function])[0]);
    }

    /**
     * The average of $column over the rows of the query, for a call of $method: that of an exact column from its SUM and
     * its COUNT, which come in one statement, and the database's AVG of any other (see AggregateCaster::average()).
     *
     * @throws ActiveRecordException for an empty column name, or *
     */
    private function average_of(string $method, string $column): string|float|null
    {
        $kind = $this->aggregate_kind($method, $column);

        if (AggregateCaster::INTEGER === $kind || AggregateCaster::DECIMAL === $kind) {
            [$sum, $count] = $this->aggregate_row($column, ['SUM', 'COUNT']);

            return AggregateCaster::average($sum, $count);
        }

        /** @var string|float|null $average */
        $average = AggregateCaster::cast($kind, 'AVG', $this->aggregate_row($column, ['AVG'])[0]);

        return $average;
    }

    /**
     * Checks the column given to an aggregate, before any statement, and says which kind it is of (see
     * AggregateCaster::kind()): a column of the model by the one rule of the builder (see QueryCompiler::column()), of the
     * type the connection reports for it, and none for any other name.
     *
     * @return 'integer'|'decimal'|'floating'|null
     * @throws ActiveRecordException for an empty column name, and for *, which only count() takes
     */
    private function aggregate_kind(string $method, string $column): ?string
    {
        $compiler = $this->compiler();
        $compiler->refuse_empty_column_name($column, $method);

        if ('*' === $column) {
            throw new ActiveRecordException(sprintf("%s() takes a column name, not '*', on %s", $method, $this->model_class));
        }

        return AggregateCaster::kind($compiler->model_column($column), $this->table()->connection()->exact_decimals());
    }

    /**
     * The one row that $functions give over $column, as the driver returns it, in the order of $functions: a row of NULLs
     * if the statement gives none. The statement is left in Table::$last_sql.
     *
     * @param list<'COUNT'|'MIN'|'MAX'|'SUM'|'AVG'> $functions
     * @return list<mixed>
     */
    private function aggregate_row(string $column, array $functions): array
    {
        $table = $this->table();
        $sql = $this->compiler()->compile_aggregate($this->state, $column, $functions);
        $values = $table->format_date_bind_values($sql->select_bind_values());
        $row = $table->connection()->query(($table->last_sql = $sql->to_s()), $values)->fetch(\PDO::FETCH_NUM);

        return is_array($row) ? array_values($row) : array_fill(0, count($functions), null);
    }

    /**
     * How a value of $column is read: cast as a model casts its attribute when $column names a column of
     * the model (see QueryCompiler::model_column()), else as the driver returns it.
     *
     * @return Closure(mixed): mixed
     */
    private function caster(string $column): Closure
    {
        $model_column = $this->compiler()->model_column($column);
        $connection = $this->table()->connection();

        if (null === $model_column) {
            return fn(mixed $value): mixed => $value;
        }

        return fn(mixed $value): mixed => $model_column->cast($value, $connection);
    }

    /**
     * An id as the messages of the legacy finders by pk write it (Model::find_by_pk()): a date and time
     * without a text of its own in the datetime format.
     */
    private static function describe_id(mixed $id): string
    {
        return match (true) {
            $id instanceof \DateTimeInterface && !$id instanceof \Stringable => $id->format('Y-m-d H:i:s'),
            is_scalar($id), null === $id, $id instanceof \Stringable => (string) $id,
            default => get_debug_type($id),
        };
    }

    /**
     * $value as PHP takes it for the key of an array, spelled out where PHP would not take it as it is: a
     * null is '', a boolean 0 or 1, a float its text.
     */
    private static function array_key(mixed $value): int|string
    {
        return match (true) {
            is_int($value), is_string($value) => $value,
            null === $value => '',
            is_bool($value) => (int) $value,
            is_float($value), $value instanceof \Stringable => (string) $value,
            default => get_debug_type($value),
        };
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
     * Adds the join of a call of $method, join(), left_join() or right_join() (see join()), of the type $type:
     * $table, and the conditions the closure $first writes, or the comparison of the column $first with the
     * column $second by $operator. $count is the number of the arguments given after $first.
     *
     * @param 'INNER'|'LEFT'|'RIGHT' $type
     * @return $this
     * @throws ActiveRecordException as join() does
     */
    private function add_join(string $method, string $type, string $table, Closure|string $first, ?string $operator, ?string $second, int $count): static
    {
        // read, and refused if need be, at the call (spec §5.7)
        $reference = $this->compiler()->table_reference($table, null, $method);

        if ($first instanceof Closure) {
            $join = new JoinClause($type, $table, $this->conditions());
            $first($join);
            // taken now: a later change to the JoinClause changes nothing here
            $wheres = $join->wheres();

            if ([] === $wheres) {
                throw new ActiveRecordException(sprintf("The closure given to %s() on %s adds no condition to the join of '%s': cross_join() joins without one", $method, $this->model_class, $table));
            }
        } else {
            $wheres = [$this->conditions()->column_comparison($method, $first, $operator, $second, $count, boolean: 'AND')];
        }

        $this->state->joins[] = ['type' => $type, 'table' => $reference['table'], 'alias' => $reference['alias'], 'wheres' => $wheres];

        return $this;
    }

    /**
     * Adds the joins of these relationships and JOIN fragments, of the type $type (see
     * Table::create_joins_of_type()), as their SQL: the legacy join SQL, unchanged, with the limits that
     * joins() states; none for no names.
     *
     * @param list<string> $names
     * @param 'INNER'|'LEFT' $type
     * @return $this
     * @throws RelationshipException for a name that is no relationship of the model
     */
    private function add_relationship_joins(array $names, string $type): static
    {
        if ([] !== $names) {
            $this->state->joins[] = $this->table()->create_joins_of_type($names, $type);
        }

        return $this;
    }

    /**
     * The names given to $method, joins() or left_joins(): a name, or a list of them.
     *
     * @param string|array<mixed> $names
     * @return list<string>
     * @throws ActiveRecordException for an entry that is not a string
     */
    private function relationship_names(string $method, string|array $names): array
    {
        $list = [];

        foreach (is_array($names) ? $names : [$names] as $name) {
            if (!is_string($name)) {
                throw new ActiveRecordException(sprintf('A relationship name must be a string, not %s, in %s() on %s', get_debug_type($name), $method, $this->model_class));
            }

            $list[] = $name;
        }

        return $list;
    }

    /**
     * Adds an order by a column to the ORDER BY, after those it holds, for a call of $method.
     *
     * @return $this
     * @throws ActiveRecordException for a direction other than asc and desc, or an empty column name
     */
    private function add_order(string $method, string $column, string $direction): static
    {
        $this->state->orders[] = $this->column_order($method, $column, $this->order_direction($method, $direction));

        return $this;
    }

    /**
     * The order by a column of a call of $method, in the direction $direction (see order_direction()): the name
     * is read by column() when the statement is compiled.
     *
     * @param 'ASC'|'DESC' $direction
     * @return array{type: 'column', column: string, direction: 'ASC'|'DESC'}
     * @throws ActiveRecordException for an empty column name
     */
    private function column_order(string $method, string $column, string $direction): array
    {
        $this->compiler()->refuse_empty_column_name($column, $method);

        return ['type' => 'column', 'column' => $column, 'direction' => $direction];
    }

    /**
     * The direction of an order given to $method, in upper case: asc or desc, in any case.
     *
     * @return 'ASC'|'DESC'
     * @throws ActiveRecordException for any other
     */
    private function order_direction(string $method, string $direction): string
    {
        return match (strtolower($direction)) {
            'asc' => 'ASC',
            'desc' => 'DESC',
            default => throw new ActiveRecordException(sprintf("Invalid order direction '%s' in %s() on %s", $direction, $method, $this->model_class)),
        };
    }

    /**
     * Adds conditions to the HAVING, after those it holds.
     *
     * @param list<Where> $havings
     * @return $this
     */
    private function add_havings(array $havings): static
    {
        $this->state->havings = [...$this->state->havings, ...$havings];

        return $this;
    }

    /**
     * The number given to $method, limit() or offset() or one of their other names: at least 0.
     *
     * @throws ActiveRecordException for a negative one
     */
    private function window_value(string $method, int $value): int
    {
        if ($value < 0) {
            throw new ActiveRecordException(sprintf('%s() must be >= 0, not %d, on %s', $method, $value, $this->model_class));
        }

        return $value;
    }

    /**
     * What when() and unless() do: the callback runs when the truth of $value is $when (true for when(), false for
     * unless()), else the default does, if there is one. A Closure given as the value is called with the query, and
     * what it returns is the value.
     *
     * @param callable(static, mixed): mixed $callback
     * @param (callable(static, mixed): mixed)|null $default
     * @return static The query, or the query on the model that the callback handed back
     */
    private function conditional(bool $when, mixed $value, callable $callback, ?callable $default): static
    {
        $value = $value instanceof Closure ? $value($this) : $value;
        $run = $when === (bool) $value ? $callback : $default;

        if (null === $run) {
            return $this;
        }

        $result = $run($this, $value);

        // a query on another model is no query of this chain: it is of no use, as is anything else that is no query
        return $result instanceof static && $result->model_class === $this->model_class ? $result : $this;
    }

    /**
     * Refuses a path that names a relationship that its model does not declare, with the RelationshipException
     * that the legacy 'include' option throws for it: the first name is the model's, and each one after it the
     * relationship's before it, whose model is loaded to look for it.
     *
     * @param list<list<string>> $paths
     * @throws RelationshipException
     */
    private function refuse_undeclared_relationships(array $paths): void
    {
        foreach ($paths as $path) {
            $table = $this->table();
            $last = count($path) - 1;

            foreach ($path as $i => $name) {
                // the throw narrows the type for PHPStan: get_relationship(..., true) throws for an undeclared name
                $relationship = $table->get_relationship($name, true)
                    ?? throw new RelationshipException(sprintf('Relationship named %s has not been declared for class: %s', $name, $table->class->getName()));

                if ($i < $last) {
                    $table = $relationship->get_table();
                }
            }
        }
    }

    /**
     * The reader of the arguments of with(), without() and with_only(), and the rules by which they change the
     * relationships the query loads (see IncludeTree).
     */
    private function include_tree(): IncludeTree
    {
        return new IncludeTree($this->model_class);
    }

    /**
     * The reader of the arguments of the where methods (see Conditions), which refuses at the call what it
     * cannot read.
     */
    private function conditions(): Conditions
    {
        return new Conditions($this->table(), $this->model_class);
    }

    /**
     * The conditions of a call of $method, where() or one of its or_ and not forms (see where()),
     * joined to those before them by $boolean, and negated with $not. $count is the number of the
     * arguments given after the column.
     *
     * @param Closure|string|array<mixed> $column
     * @param 'AND'|'OR' $boolean
     * @return list<Where>
     * @throws ActiveRecordException
     */
    private function wheres(string $method, Closure|string|array $column, mixed $operator, mixed $value, int $count, string $boolean, bool $not): array
    {
        if ($column instanceof Closure) {
            return $this->conditions()->group($this->closure_wheres($method, $column), boolean: $boolean, not: $not);
        }

        if (is_array($column)) {
            $wheres = $this->array_wheres($method, $column);

            // ANDed, they are as many conditions ANDed; with an OR or a NOT, one group
            return 'AND' === $boolean && !$not ? $wheres : $this->conditions()->group($wheres, boolean: $boolean, not: $not);
        }

        $where = $this->conditions()->comparison($method, $column, $operator, $value, $count, boolean: $boolean);

        return $not ? $this->conditions()->group([$where], boolean: $boolean, not: true) : [$where];
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
     * column => value, which is rendered as the legacy finders render one (Expressions); any other entry
     * is a condition of a list, an array of where()'s arguments: [column, value] or [column, operator,
     * value], where the column may be a closure or a list too. They are ANDed.
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
                $wheres[] = $this->conditions()->hash($method, $key, $condition);
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

            $wheres = [...$wheres, ...$this->wheres($method, $column, $condition[1] ?? null, $condition[2] ?? null, count($condition) - 1, boolean: 'AND', not: false)];
        }

        return $wheres;
    }
}
