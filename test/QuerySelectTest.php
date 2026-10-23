<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\Query;
use ActiveRecord\QueryCompiler;
use ActiveRecord\QueryState;
use ActiveRecord\SQLBuilder;

/**
 * The selection family of the query builder (select, add_select, select_raw, distinct, from, from_raw),
 * its debug methods (to_sql, get_bindings), and the rule by which every clause of the builder names a
 * column (spec §5.1, QueryCompiler::column()).
 */
class QuerySelectTest extends DatabaseTest
{
    /**
     * A join of the books to their authors, whose table has a name column too. The builder's join
     * methods are not of this family: a test that needs joins records this one in the query's state.
     */
    private const string JOIN_AUTHORS = 'INNER JOIN authors ON(books.author_id = authors.author_id)';

    /**
     * Each identifier wrapped in the quote character of the connection, joined with dots.
     */
    private function quoted(string ...$identifiers): string
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        return implode('.', array_map(fn(string $identifier): string => $q . $identifier . $q, $identifiers));
    }

    /**
     * A builder state with these joins (SQL), and the FROM that from() or from_raw() records.
     *
     * @param list<string> $joins
     */
    private function state(array $joins = [], ?string $from = null, ?string $as = null, ?string $from_raw = null): QueryState
    {
        $state = new QueryState();
        $state->joins = $joins;
        $state->from = $from;
        $state->from_alias = $as;
        $state->from_raw = $from_raw;

        return $state;
    }

    /**
     * How a builder clause of a query on $model names $name.
     *
     * @param class-string<ActiveRecord\Model> $model
     */
    private function column(string $model, string $name, ?QueryState $state = null): string
    {
        return (new QueryCompiler($model::table()))->column($name, $state ?? new QueryState());
    }

    /**
     * @param list<ActiveRecord\Model> $models
     * @return list<mixed>
     */
    private function sorted_values(array $models, string $attribute): array
    {
        $values = array_map(fn(ActiveRecord\Model $model): mixed => $model->$attribute, $models);
        sort($values);

        return $values;
    }

    // Review Focus 2
    public function test_select_maps_attributes_and_aliases_to_real_columns(): void
    {
        $this->assert_sql_has('SELECT space name FROM odd_columns', OddColumn::new_query()->select('space_name')->to_sql());
        $this->assert_sql_has('SELECT name FROM venues', Venue::new_query()->select('marquee')->to_sql());
    }

    public function test_select_alias_distinct_and_raw_binds(): void
    {
        $q = Author::new_query()->distinct()->select('name as n')->select_raw('LENGTH(name) > ? AS long_name', [3]);
        $this->assert_sql_has('SELECT DISTINCT name AS n, LENGTH(name) > ? AS long_name', $q->to_sql());
        $this->assert_same([3], $q->get_bindings());
    }

    public function test_from_alias_and_from_raw(): void
    {
        $this->assert_sql_has('FROM books AS b', Book::new_query()->from('books', 'b')->to_sql());
        $q = Book::new_query()->from_raw('(SELECT * FROM books WHERE book_id > ?) books', [0]);
        $this->assert_sql_has('FROM (SELECT * FROM books WHERE book_id > ?) books', $q->to_sql());
        $this->assert_same([0], $q->get_bindings());
    }

    // -- the column-reference rule (spec §5.1)

    // Review Focus 2: an alias, an attribute name and the column's own name all name the real column
    public function test_a_column_of_the_model_is_named_by_its_real_name(): void
    {
        $this->assert_same($this->quoted('name'), $this->column(Venue::class, 'marquee'));
        $this->assert_same($this->quoted('city'), $this->column(Venue::class, 'mycity'));
        $this->assert_same($this->quoted('name'), $this->column(Venue::class, 'name'));
        $this->assert_same($this->quoted('space name'), $this->column(OddColumn::class, 'space_name'));
        $this->assert_same($this->quoted('space name'), $this->column(OddColumn::class, 'space name'));
        $this->assert_same($this->quoted('dash-name'), $this->column(OddColumn::class, 'dash_name'));
        $this->assert_same($this->quoted('some day'), $this->column(OddColumn::class, 'some_day'));
        // the alias of an attribute name: nick is space_name, which is the column "space name"
        $this->assert_same($this->quoted('space name'), $this->column(OddColumnAliased::class, 'nick'));
        // Postgres needs the case of the column camelCase; MySQL, MariaDB and SQLite match any case
        $this->assert_same($this->quoted($this->conn->column_names_are_case_sensitive() ? 'camelCase' : 'camelcase'), $this->column(OddColumn::class, 'camelcase'));
    }

    // A pre-quoted name is the column it quotes: it is read through no alias and no attribute name
    public function test_a_pre_quoted_name_is_the_column_it_quotes(): void
    {
        $this->assert_same($this->quoted('space name'), $this->column(OddColumn::class, $this->quoted('space name')));
        $this->assert_same($this->quoted('space_name'), $this->column(OddColumn::class, $this->quoted('space_name')));
        $this->assert_same($this->quoted('marquee'), $this->column(Venue::class, $this->quoted('marquee')));
    }

    // Spec §5.1 rule 2: with joins a column of the model gets its table, so that a joined table's column
    // of the same name never makes it ambiguous. A name is the model's as the database matches names,
    // as a hash of conditions with joins decides it (spec §6.2.4).
    public function test_with_joins_a_column_of_the_model_gets_its_table(): void
    {
        $joins = $this->state([self::JOIN_AUTHORS]);

        $this->assert_same($this->quoted('books', 'name'), $this->column(Book::class, 'name', $joins));
        // MySQL, MariaDB and SQLite name Book's column Author_Id, and match it in any case
        $this->assert_same($this->quoted('books', 'author_id'), $this->column(Book::class, 'author_id', $joins));
        $this->assert_same($this->quoted('books', 'name'), $this->column(Book::class, $this->quoted('name'), $joins));
        $this->assert_same($this->quoted('venues', 'name'), $this->column(Venue::class, 'marquee', $joins));
        $this->assert_same($this->quoted('odd_columns', 'space name'), $this->column(OddColumn::class, 'space_name', $joins));

        // and so does a column that every table has without listing it (rowid, ctid, ...)
        foreach ($this->conn->pseudo_columns() as $pseudo_column) {
            $this->assert_same($this->quoted('books', $pseudo_column), $this->column(Book::class, $pseudo_column, $joins));
        }

        // NAME is the column name only where the database ignores the case of names
        $this->assert_same(
            $this->conn->column_names_are_case_sensitive() ? $this->quoted('NAME') : $this->quoted('books', 'NAME'),
            $this->column(Book::class, 'NAME', $joins)
        );
    }

    // A name that is not the model's stays as written: a joined table's column, the alias of an expression
    public function test_with_joins_any_other_name_stays_unqualified(): void
    {
        $joins = $this->state([self::JOIN_AUTHORS]);

        $this->assert_same($this->quoted('parent_author_id'), $this->column(Book::class, 'parent_author_id', $joins));
        $this->assert_same($this->quoted('long_name'), $this->column(Book::class, 'long_name', $joins));
    }

    public function test_without_joins_no_name_is_qualified(): void
    {
        $this->assert_same($this->quoted('name'), $this->column(Book::class, 'name'));
        $this->assert_same($this->quoted('name'), $this->column(Book::class, 'name', $this->state([], 'books', 'b')));
    }

    // With joins the model's columns get the name the FROM gives its rows: the from() alias, else the
    // table from() reads them from, which stands for the model's. A raw FROM names its rows itself, and
    // nothing is qualified (spec §5.1).
    public function test_with_joins_a_column_of_the_model_gets_the_name_the_from_gives_its_rows(): void
    {
        $this->assert_same($this->quoted('b', 'name'), $this->column(Book::class, 'name', $this->state([self::JOIN_AUTHORS], 'books', 'b')));
        $this->assert_same($this->quoted('o', 'space name'), $this->column(OddColumn::class, 'space_name', $this->state([self::JOIN_AUTHORS], 'odd_columns', 'o')));
        $this->assert_same($this->quoted('archived_books', 'name'), $this->column(Book::class, 'name', $this->state([self::JOIN_AUTHORS], 'archived_books')));
        $this->assert_same($this->quoted('archive', 'books', 'name'), $this->column(Book::class, 'name', $this->state([self::JOIN_AUTHORS], 'archive.books')));
        $this->assert_same($this->quoted('name'), $this->column(Book::class, 'name', $this->state([self::JOIN_AUTHORS], from_raw: 'books b')));
    }

    // Spec §5.1 rule 3: table.column, table.* and * are quoted part by part, as written: read through no
    // alias and no attribute name, and given no other table
    public function test_qualified_names_and_star_are_quoted_part_by_part(): void
    {
        foreach ([$this->state(), $this->state([self::JOIN_AUTHORS])] as $state) {
            $this->assert_same('*', $this->column(Book::class, '*', $state));
            $this->assert_same($this->quoted('authors') . '.*', $this->column(Book::class, 'authors.*', $state));
            $this->assert_same($this->quoted('authors', 'name'), $this->column(Book::class, 'authors.name', $state));
            $this->assert_same($this->quoted('books', 'name'), $this->column(Book::class, 'books.name', $state));
            $this->assert_same($this->quoted('library', 'books', 'name'), $this->column(Book::class, 'library.books.name', $state));
            $this->assert_same($this->quoted('books', 'name'), $this->column(Book::class, $this->quoted('books') . '.name', $state));
            $this->assert_same($this->quoted('venues', 'marquee'), $this->column(Venue::class, 'venues.marquee', $state));
            $this->assert_same($this->quoted('odd_columns', 'space_name'), $this->column(OddColumn::class, 'odd_columns.space_name', $state));
        }
    }

    // A name is always identifiers, never SQL (#64): an expression goes through select_raw()
    public function test_a_name_is_never_sent_as_sql(): void
    {
        $q = $this->conn::$QUOTE_CHARACTER;

        foreach ([$this->state(), $this->state([self::JOIN_AUTHORS])] as $state) {
            $this->assert_same($this->quoted('COUNT(*)'), $this->column(Book::class, 'COUNT(*)', $state));
            $this->assert_same($this->quoted('name) FROM books --'), $this->column(Book::class, 'name) FROM books --', $state));
            // no dotted name of identifiers (an empty part, a quote left open): one identifier, its quotes doubled
            $this->assert_same($this->quoted('books..name'), $this->column(Book::class, 'books..name', $state));
            $this->assert_same("$q$q{$q}name$q", $this->column(Book::class, "{$q}name", $state));
        }
    }

    // -- select, add_select, select_raw, distinct

    public function test_select_names_each_column_by_the_rule_and_its_alias_as_one_identifier(): void
    {
        $this->assert_same(
            'SELECT ' . $this->quoted('name') . ' AS ' . $this->quoted('n') . ', ' . $this->quoted('author_id') . ' AS ' . $this->quoted('id')
            . ', ' . $this->quoted('parent_author_id') . ' FROM ' . $this->quoted('authors'),
            Author::new_query()->select('name as n', 'author_id AS id', 'parent_author_id')->to_sql()
        );
        $this->assert_same(
            'SELECT ' . $this->quoted('name') . ' AS ' . $this->quoted('first name') . ' FROM ' . $this->quoted('venues'),
            Venue::new_query()->select('marquee  As  first name')->to_sql()
        );
        // an alias is no column: no alias of the model maps it, and a dot does not part it
        $this->assert_same(
            'SELECT ' . $this->quoted('city') . ' AS ' . $this->quoted('marquee') . ', ' . $this->quoted('name') . ' AS ' . $this->quoted('venues.name') . ' FROM ' . $this->quoted('venues'),
            Venue::new_query()->select('city as marquee', 'name as venues.name')->to_sql()
        );
    }

    // Review Focus 2: the models hold the selected columns under their attribute names, and an aliased
    // column under its alias
    public function test_the_models_of_a_select_hold_what_it_selects(): void
    {
        OddColumn::create(['space_name' => 'v', 'dash_name' => 'w', 'camelcase' => 'c']);

        [$odd] = OddColumn::new_query()->select('space_name')->get();
        $this->assert_same(['space_name' => 'v'], $odd->attributes());
        [$odd] = OddColumn::new_query()->select('camelcase')->get();
        $this->assert_same(['camelcase' => 'c'], $odd->attributes());

        $names = [];

        foreach (Venue::all() as $venue) {
            $names[$venue->id] = $venue->name;
        }

        $marquees = [];

        foreach (Venue::new_query()->select('marquee', 'id')->get() as $venue) {
            $marquees[$venue->id] = $venue->marquee;
        }

        ksort($names);
        ksort($marquees);

        $this->assert_same(6, count($marquees));
        $this->assert_same($names, $marquees);

        $aliased = [];

        foreach (Author::new_query()->select('name as n', 'author_id')->get() as $author) {
            $aliased[$author->author_id] = $author->n;
        }
        ksort($aliased);

        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], $aliased);
    }

    public function test_select_replaces_the_columns_and_add_select_adds_to_them(): void
    {
        $from = ' FROM ' . $this->quoted('authors');

        $this->assert_same('SELECT ' . $this->quoted('author_id') . $from, Author::new_query()->select('name')->select('author_id')->to_sql());
        $this->assert_same(
            'SELECT ' . $this->quoted('name') . ', ' . $this->quoted('author_id') . ', ' . $this->quoted('parent_author_id') . $from,
            Author::new_query()->select(['name'])->add_select('author_id', ['parent_author_id'])->to_sql()
        );
        // as in Eloquent, add_select() on a query that selects nothing selects only what it is given
        $this->assert_same('SELECT ' . $this->quoted('name') . $from, Author::new_query()->add_select('name')->to_sql());
        // as in Eloquent, add_select() leaves out a column the query already selects
        $this->assert_same(
            'SELECT ' . $this->quoted('name') . ', ' . $this->quoted('author_id') . $from,
            Author::new_query()->select('name')->add_select('name', 'author_id')->to_sql()
        );
        // select() without columns selects the default again
        $this->assert_same('SELECT *' . $from, Author::new_query()->select('name')->select()->to_sql());
    }

    // select_raw() adds its expression and its bindings to the select; select() replaces both
    public function test_select_raw_adds_an_expression_with_its_bindings(): void
    {
        $query = Author::new_query()->select('name')->select_raw('? AS one', [1])->add_select('author_id')->select_raw('? + ? AS three', [1, 2]);

        $this->assert_same('SELECT ' . $this->quoted('name') . ', ? AS one, ' . $this->quoted('author_id') . ', ? + ? AS three FROM ' . $this->quoted('authors'), $query->to_sql());
        $this->assert_same([1, 1, 2], $query->get_bindings());

        $query->select('name');
        $this->assert_same('SELECT ' . $this->quoted('name') . ' FROM ' . $this->quoted('authors'), $query->to_sql());
        $this->assert_same([], $query->get_bindings());
    }

    public function test_the_bindings_of_select_raw_are_sent_when_the_query_runs(): void
    {
        $authors = Author::new_query()->select('author_id')->select_raw('author_id * ? AS scaled', [10])->get();
        $scaled = [];

        foreach ($authors as $author) {
            $scaled[$author->author_id] = (int) $author->scaled;
        }
        ksort($scaled);

        $this->assert_same([1 => 10, 2 => 20, 3 => 30, 4 => 40], $scaled);
    }

    // Spec §5.7: the call that introduces the problem throws, and leaves the query as it was
    public function test_select_and_add_select_refuse_what_is_no_column_name(): void
    {
        $query = Author::new_query()->select('name');

        $this->assert_exception_message_contains('select() on Author', fn() => $query->select(['author_id', 7]), ActiveRecordException::class);
        $this->assert_exception_message_contains('add_select() on Author', fn() => $query->add_select([['author_id']]), ActiveRecordException::class);
        $this->assert_same('SELECT ' . $this->quoted('name') . ' FROM ' . $this->quoted('authors'), $query->to_sql());
    }

    public function test_distinct_selects_each_row_once(): void
    {
        $this->assert_same('SELECT DISTINCT * FROM ' . $this->quoted('authors'), Author::new_query()->distinct()->to_sql());
        $this->assert_same('SELECT * FROM ' . $this->quoted('authors'), Author::new_query()->distinct()->distinct(false)->to_sql());

        // the parents of the four authors are 3, 2, 1 and 2
        $this->assert_same([1, 2, 3], $this->sorted_values(Author::new_query()->distinct()->select('parent_author_id')->get(), 'parent_author_id'));
        $this->assert_same([1, 2, 2, 3], $this->sorted_values(Author::new_query()->select('parent_author_id')->get(), 'parent_author_id'));
    }

    // -- from, from_raw

    public function test_from_names_a_table_part_by_part_and_its_alias(): void
    {
        $books_as_b = 'SELECT * FROM ' . $this->quoted('books') . ' AS ' . $this->quoted('b');

        $this->assert_same($books_as_b, Book::new_query()->from('books', 'b')->to_sql());
        $this->assert_same($books_as_b, Book::new_query()->from('books as b')->to_sql());
        $this->assert_same($books_as_b, Book::new_query()->from('books  AS  b')->to_sql());
        $this->assert_same('SELECT * FROM ' . $this->quoted('library', 'books'), Book::new_query()->from('library.books')->to_sql());

        $this->assert_same(['Ancient Art of Main Tanking', 'Another Book'], $this->sorted_values(Book::new_query()->from('books', 'b')->select('b.name')->get(), 'name'));
    }

    public function test_from_raw_is_sent_as_written_with_its_bindings(): void
    {
        $after = fn(int $id): Query => Book::new_query()->from_raw('(SELECT * FROM books WHERE book_id > ?) books', [$id]);

        $this->assert_same([2], $this->sorted_values($after(1)->get(), 'book_id'));
        $this->assert_true($after(1)->exists());
        $this->assert_false($after(2)->exists());
    }

    // There is one FROM: from() and from_raw() replace each other, the bindings of a raw one included
    public function test_from_and_from_raw_replace_each_other(): void
    {
        $query = Book::new_query()->from_raw('(SELECT * FROM books WHERE book_id > ?) books', [1])->from('books');

        $this->assert_same('SELECT * FROM ' . $this->quoted('books'), $query->to_sql());
        $this->assert_same([], $query->get_bindings());

        $query->from_raw('books b');
        $this->assert_same('SELECT * FROM books b', $query->to_sql());
    }

    // -- the default select, and joins

    // The default select is every column, or with joins those of the model's rows only (as the legacy
    // options select by default): a joined table's columns would otherwise come too, and the authors'
    // name would overwrite the books'.
    public function test_the_default_select_is_every_column_or_with_joins_those_of_the_models_rows(): void
    {
        $query = Book::new_query();
        $this->assert_same('SELECT * FROM ' . $this->quoted('books'), $query->to_sql());

        $query->state()->joins[] = self::JOIN_AUTHORS;
        $this->assert_same('SELECT ' . $this->quoted('books') . '.* FROM ' . $this->quoted('books') . ' ' . self::JOIN_AUTHORS, $query->to_sql());
        $this->assert_same(['Ancient Art of Main Tanking', 'Another Book'], $this->sorted_values($query->get(), 'name'));

        $query->distinct();
        $this->assert_same('SELECT DISTINCT ' . $this->quoted('books') . '.* FROM ' . $this->quoted('books') . ' ' . self::JOIN_AUTHORS, $query->to_sql());
    }

    public function test_with_joins_the_default_select_reads_the_rows_by_the_name_the_from_gives_them(): void
    {
        $query = Book::new_query()->from('books', 'b');
        $query->state()->joins[] = 'INNER JOIN authors ON(b.author_id = authors.author_id)';

        $this->assert_same('SELECT ' . $this->quoted('b') . '.* FROM ' . $this->quoted('books') . ' AS ' . $this->quoted('b') . ' INNER JOIN authors ON(b.author_id = authors.author_id)', $query->to_sql());
        $this->assert_same(['Ancient Art of Main Tanking', 'Another Book'], $this->sorted_values($query->get(), 'name'));

        // a raw FROM: the model's table, as the legacy 'from' option selects it
        $query = Book::new_query()->from_raw('(SELECT * FROM books WHERE book_id > ?) books', [1]);
        $query->state()->joins[] = self::JOIN_AUTHORS;

        $this->assert_same('SELECT ' . $this->quoted('books') . '.* FROM (SELECT * FROM books WHERE book_id > ?) books ' . self::JOIN_AUTHORS, $query->to_sql());
        $this->assert_same(['Another Book'], $this->sorted_values($query->get(), 'name'));
    }

    // Spec §5.1 rule 2 at work: the authors have a name too, which the books' would be ambiguous with
    public function test_with_joins_the_selected_columns_of_the_model_are_never_ambiguous(): void
    {
        $query = Book::new_query()->select('book_id', 'name', 'authors.name as author_name');
        $query->state()->joins[] = self::JOIN_AUTHORS;

        $this->assert_same(
            'SELECT ' . $this->quoted('books', 'book_id') . ', ' . $this->quoted('books', 'name') . ', ' . $this->quoted('authors', 'name') . ' AS ' . $this->quoted('author_name')
            . ' FROM ' . $this->quoted('books') . ' ' . self::JOIN_AUTHORS,
            $query->to_sql()
        );

        $books = [];

        foreach ($query->get() as $book) {
            $books[$book->book_id] = [$book->name, $book->author_name];
        }
        ksort($books);

        $this->assert_same([1 => ['Ancient Art of Main Tanking', 'Tito'], 2 => ['Another Book', 'George W. Bush']], $books);
    }

    // -- bindings, to_sql, forwarding

    // The bindings of a SELECT come in the order of its clauses, whatever the order of the calls
    public function test_the_bindings_come_in_the_order_of_the_clauses(): void
    {
        $query = Book::new_query()
            ->from_raw('(SELECT * FROM books WHERE book_id > ?) books', ['from'])
            ->select_raw('? AS a', ['select 1'])
            ->add_select('name')
            ->select_raw('? AS b', ['select 2']);

        $this->assert_same(['select 1', 'select 2', 'from'], $query->get_bindings());

        $sql = new SQLBuilder($this->conn, 'authors');
        $sql->where('name = ?', 'where')->from_values(['from'])->select('? AS a', ['select']);

        $this->assert_same(['select', 'from', 'where'], $sql->select_bind_values());
        $this->assert_same(['where'], $sql->get_where_values());
    }

    // to_sql() and get_bindings() describe a legacy query too: its SELECT, and the values of its WHERE
    public function test_to_sql_and_get_bindings_of_a_legacy_query(): void
    {
        $query = Query::from_options(Author::table(), ['conditions' => ['name' => 'Tito'], 'order' => 'author_id']);

        $this->assert_same('SELECT * FROM ' . $this->quoted('authors') . ' WHERE ' . $this->quoted('name') . '=? ORDER BY author_id', $query->to_sql());
        $this->assert_same(['Tito'], $query->get_bindings());
    }

    public function test_the_selection_methods_are_reached_statically(): void
    {
        $authors = ' FROM ' . $this->quoted('authors');

        $this->assert_same('SELECT ' . $this->quoted('name') . $authors, Author::select('name')->to_sql());
        $this->assert_same('SELECT ' . $this->quoted('name') . $authors, Author::add_select('name')->to_sql());
        $this->assert_same('SELECT 1 AS one' . $authors, Author::select_raw('1 AS one')->to_sql());
        $this->assert_same('SELECT DISTINCT *' . $authors, Author::distinct()->to_sql());
        $this->assert_same('SELECT * FROM ' . $this->quoted('books') . ' AS ' . $this->quoted('b'), Book::from('books', 'b')->to_sql());
        $this->assert_same('SELECT * FROM books b', Book::from_raw('books b')->to_sql());
        $this->assert_same('SELECT *' . $authors, Author::to_sql());
        $this->assert_same([], Author::get_bindings());
    }

    // A query is mutable (spec §3.2): each method changes the query it is called on and returns it
    public function test_the_selection_methods_return_the_query_they_change(): void
    {
        $query = Author::new_query();

        foreach ([
            fn(Query $q) => $q->select('name'),
            fn(Query $q) => $q->add_select('author_id'),
            fn(Query $q) => $q->select_raw('1 AS one'),
            fn(Query $q) => $q->distinct(),
            fn(Query $q) => $q->from('authors', 'a'),
            fn(Query $q) => $q->from_raw('authors a'),
        ] as $call) {
            $this->assert_same($query, $call($query));
        }

        $this->assert_same('SELECT DISTINCT ' . $this->quoted('name') . ', ' . $this->quoted('author_id') . ', 1 AS one FROM authors a', $query->to_sql());
    }
}
