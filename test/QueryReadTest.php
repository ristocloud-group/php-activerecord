<?php

use ActiveRecord\Model;
use ActiveRecord\Query;
use ActiveRecord\QueryCompiler;

/**
 * The read terminals of the query builder (spec §5.3): first, first_or_fail, first_or, sole, find, find_or_fail,
 * find_many, find_or, value, sole_value, pluck, exists, doesnt_exist and count. Each runs the query as it stands and
 * leaves it so. The finds by primary key read their ids as the legacy finders by pk do (QueryCompiler's
 * pk_value_for_column() and keep_integer_pk_values()), and the values of a column are cast as a model casts its
 * attributes.
 *
 * Fixture data: authors 1 Tito (parent 3), 2 George W. Bush (parent 2), 3 Bill Clinton (parent 1) and 4 Uncle Bob
 * (parent 2); dated_counts (2026-01-01, 1 hit) and (2026-01-02, 2 hits); big_ids 1 and PHP_INT_MAX ('int max');
 * books 1 and 2, of authors 1 and 2, whose NUMERIC special is 0.
 */
class QueryReadTest extends DatabaseTest
{
    /**
     * $sql with each backtick replaced by the quote character of the connection.
     */
    private function sql(string $sql): string
    {
        return str_replace('`', $this->conn::$QUOTE_CHARACTER, $sql);
    }

    /**
     * The class and the message of what $call throws, or null when it throws nothing.
     *
     * @return array{class-string<Throwable>, string}|null
     */
    private function thrown(callable $call): ?array
    {
        try {
            $call();
        } catch (Throwable $e) {
            return [$e::class, $e->getMessage()];
        }

        return null;
    }

    /**
     * The author_id of each model, in order.
     *
     * @param list<Model> $models
     * @return list<mixed>
     */
    private function ids(array $models): array
    {
        return array_map(fn(Model $model): mixed => $model->author_id, $models);
    }

    public function test_first_sole_and_failures(): void
    {
        $this->assert_same('Tito', Author::where('author_id', 1)->first()->name);
        $this->assert_exception_message_contains('', fn() => Author::where('author_id', 99)->first_or_fail(), ActiveRecord\RecordNotFound::class);
        $this->assert_same('none', Author::where('author_id', 99)->first_or(fn() => 'none'));
        $this->assert_exception_message_contains('', fn() => Author::where('author_id', '>', 1)->sole(), ActiveRecord\MultipleRecordsFound::class);
        $this->assert_same(1, Author::where('name', 'Tito')->sole()->author_id);
        $this->assert_same('Tito', Author::where('author_id', 1)->sole_value('name'));
    }

    public function test_find_forms(): void
    {
        $this->assert_null(Author::new_query()->find(99));
        $this->assert_same([1, 2], array_map(fn($a) => $a->author_id, Author::order_by('author_id')->find([1, 2, 99])));
        $this->assert_same([2, 3], array_map(fn($a) => $a->author_id, Author::order_by('author_id')->find_many([2, 3])));
        $this->assert_same('missing', Author::new_query()->find_or(99, fn() => 'missing'));
        $this->assert_exception_message_contains("Couldn't find Author with ID=99", fn() => Author::new_query()->find_or_fail(99), ActiveRecord\RecordNotFound::class);
        $this->assert_exception_message_contains('composite', fn() => DatedCount::new_query()->find(1), ActiveRecord\ActiveRecordException::class);
    }

    public function test_value_pluck_exists_count(): void
    {
        $this->assert_same(9223372036854775807, BigId::where('note', 'int max')->value('id'));
        $this->assert_same(['Tito', 'George W. Bush', 'Bill Clinton', 'Uncle Bob'], Author::order_by('author_id')->pluck('name'));
        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], Author::order_by('author_id')->pluck('name', 'author_id'));
        $this->assert_true(Author::where('name', 'Tito')->exists());
        $this->assert_true(Author::where('name', 'Nobody')->doesnt_exist());
        $this->assert_same(4, Author::new_query()->count());
        $this->assert_same(3, Author::group_by('parent_author_id')->count());
        $this->assert_same(3, Author::offset(1)->count());
        $this->assert_same(2, Author::limit(2)->count());
        $this->assert_same(3, Author::distinct()->select('parent_author_id')->count());
        // the legacy count is unchanged: that of the first group. Every name is one author's, so whichever group
        // the database returns first holds one (the groups by parent_author_id hold 1, 2 and 1, in no set order)
        $this->assert_same(1, Author::count(['group' => 'name']));
        $this->assert_same(4, Author::group_by('name')->count());
    }

    // Review Focus 2: pluck reaches the real column
    public function test_pluck_maps_attribute_names_to_real_columns(): void
    {
        OddColumn::create(['space_name' => 'v']);
        $this->assert_same(['v'], OddColumn::where('space_name', 'v')->pluck('space_name'));
    }

    // Review Focus 1: the builder does not run legacy finder overrides (spec §6.3)
    public function test_builder_bypasses_legacy_finder_overrides(): void
    {
        $this->assert_same(4, count(OverridingAuthor::new_query()->get()));
    }

    // Spec §5.7: a terminal that finds no row, or more than one where it wants one, names the terminal and the
    // model. A find by pk throws the legacy finders' RecordNotFound (Model::find_by_pk()): for one id, or for ids of
    // which some are missing, each id counted once.
    public function test_each_failure_names_the_terminal_and_the_model(): void
    {
        $none = fn(): Query => Author::where('author_id', 99);
        $many = fn(): Query => Author::where('author_id', '>', 1);

        $this->assert_same([ActiveRecord\RecordNotFound::class, 'first_or_fail() found no Author'], $this->thrown(fn() => $none()->first_or_fail()));
        $this->assert_same([ActiveRecord\RecordNotFound::class, 'sole() found no Author'], $this->thrown(fn() => $none()->sole()));
        $this->assert_same([ActiveRecord\MultipleRecordsFound::class, 'sole() found more than one Author'], $this->thrown(fn() => $many()->sole()));
        $this->assert_same([ActiveRecord\RecordNotFound::class, 'sole_value() found no Author'], $this->thrown(fn() => $none()->sole_value('name')));
        $this->assert_same([ActiveRecord\MultipleRecordsFound::class, 'sole_value() found more than one Author'], $this->thrown(fn() => $many()->sole_value('name')));
        $this->assert_true(is_subclass_of(ActiveRecord\MultipleRecordsFound::class, ActiveRecord\ActiveRecordException::class));

        $this->assert_same([ActiveRecord\RecordNotFound::class, "Couldn't find Author with ID=99"], $this->thrown(fn() => Author::new_query()->find_or_fail(99)));
        $this->assert_same([ActiveRecord\RecordNotFound::class, "Couldn't find Author with ID=99"], $this->thrown(fn() => Author::new_query()->find_or_fail([99])));
        $this->assert_same(
            [ActiveRecord\RecordNotFound::class, "Couldn't find all Author with IDs (1,99,98) (found 1, but was looking for 3)"],
            $this->thrown(fn() => Author::new_query()->find_or_fail([1, 99, 98]))
        );
        $this->assert_same('missing', Author::new_query()->find_or([1, 99], fn() => 'missing'));
        // a callback runs only when nothing, or not everything, is found
        $never = fn() => throw new LogicException('the callback ran');
        $this->assert_same('Tito', Author::where('author_id', 1)->first_or($never)->name);
        $this->assert_same('Tito', Author::new_query()->find_or(1, $never)->name);
        $this->assert_same([1, 2], $this->ids(Author::order_by('author_id')->find_or([1, 2], $never)));

        // an id given twice is one id: ids are told apart by their text in PHP ('1' for 1 and '1'), not by the
        // database's equality, so nothing is missing here
        $this->assert_same([1, 2], $this->ids(Author::order_by('author_id')->find_or_fail([1, 2, 1])));
        $this->assert_same([1], $this->ids(Author::new_query()->find_or_fail([1, '1'])));
        $this->assert_same([1, 2], $this->ids(Author::order_by('author_id')->find_or([2, 1, 2], fn() => 'missing')));
        // and a row a join repeats is one row, so an id that is missing is still missing: both books have author 2
        // as their secondary author
        $twice = fn(): Query => Author::join('books', 'books.secondary_author_id', '=', 'authors.author_id');
        $this->assert_same([2, 2], $this->ids($twice()->find_many([2])));
        $this->assert_same(
            [ActiveRecord\RecordNotFound::class, "Couldn't find all Author with IDs (2,99) (found 1, but was looking for 2)"],
            $this->thrown(fn() => $twice()->find_or_fail([2, 99]))
        );
        // a select without the key leaves the rows to count
        $this->assert_same(['Tito', 'George W. Bush'], array_map(fn(Author $author): string => $author->name, Author::select('name')->order_by('author_id')->find_or_fail([1, 2])));
    }

    // Spec §5.3 and §5.7: a find by pk needs a primary key of one column, and refuses any other before a statement
    // is sent, naming the terminal, the model, its key and the ids
    public function test_the_finds_refuse_a_key_of_several_columns_or_none(): void
    {
        DatedCount::table();
        PklessItem::table();

        $this->assert_same(
            [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => 'find() needs a single-column primary key; DatedCount has a composite one (day, at), for the id 1']],
            GoldenStatements::capture(fn() => DatedCount::new_query()->find(1))
        );
        $this->assert_same(
            [ActiveRecord\ActiveRecordException::class, 'find_many() needs a single-column primary key; DatedCount has a composite one (day, at), for the ids [1, 2]'],
            $this->thrown(fn() => DatedCount::new_query()->find_many([1, 2]))
        );
        $this->assert_same(
            [ActiveRecord\ActiveRecordException::class, 'find_or_fail() needs a single-column primary key; DatedCount has a composite one (day, at), for the id 1'],
            $this->thrown(fn() => DatedCount::new_query()->find_or_fail(1))
        );
        $this->assert_same(
            [ActiveRecord\ActiveRecordException::class, 'find_or() needs a single-column primary key; DatedCount has a composite one (day, at), for the id 1'],
            $this->thrown(fn() => DatedCount::new_query()->find_or(1, fn() => 'missing'))
        );
        $this->assert_same(
            [ActiveRecord\ActiveRecordException::class, 'find() needs a single-column primary key; PklessItem has none, for the id 1'],
            $this->thrown(fn() => PklessItem::new_query()->find(1))
        );
    }

    // Spec §5.7: a list in the list of ids is refused before any statement, as where_key() refuses it
    public function test_a_find_refuses_a_list_among_its_ids(): void
    {
        Author::table();

        $this->assert_same(
            [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => 'An array is no value in the list given to find_many() on Author, at key 1']],
            GoldenStatements::capture(fn() => Author::new_query()->find_many([1, [2]]))
        );
    }

    // Spec §6.3: find, first, count and exists are Model's own statics, so on the class they keep their legacy
    // meaning; the builder's are reached through a query. The other read terminals are forwarded to a new query.
    public function test_the_class_keeps_its_legacy_statics_and_forwards_the_other_terminals(): void
    {
        $this->assert_same([ActiveRecord\RecordNotFound::class, "Couldn't find Author with ID=99"], $this->thrown(fn() => Author::find(99)));
        $this->assert_null(Author::new_query()->find(99));

        $this->assert_false(Author::doesnt_exist());
        $this->assert_same('Tito', Author::find_or_fail(1)->name);
        // the rows of a find come in no set order without an order_by()
        $found = $this->ids(Author::find_many([2, 3]));
        sort($found);
        $this->assert_same([2, 3], $found);
        $this->assert_same('missing', Author::find_or(99, fn() => 'missing'));
        $this->assert_same(4, count(Author::pluck('name')));
        $this->assert_instance_of(Author::class, Author::first_or_fail());
        $this->assert_instance_of(Author::class, Author::first_or(fn() => 'none'));
        $this->assert_same([ActiveRecord\MultipleRecordsFound::class, 'sole() found more than one Author'], $this->thrown(fn() => Author::sole()));
        $this->assert_same([ActiveRecord\MultipleRecordsFound::class, 'sole_value() found more than one Author'], $this->thrown(fn() => Author::sole_value('name')));
        $this->assert_true(in_array(Author::value('name'), ['Tito', 'George W. Bush', 'Bill Clinton', 'Uncle Bob'], true));
    }

    // A terminal runs the query as it stands and leaves it as it was: the same SQL and bindings after each, the same
    // rows
    public function test_the_terminals_leave_the_query_as_it_was(): void
    {
        $query = Author::where('author_id', '>', 1)->order_by('author_id');
        $sql = $query->to_sql();
        $bindings = $query->get_bindings();

        $this->assert_same(2, $query->first()->author_id);
        $this->assert_same(3, $query->find(3)->author_id);
        $this->assert_same([2, 4], $this->ids($query->find_many([2, 4])));
        $this->assert_same('George W. Bush', $query->value('name'));
        $this->assert_same(['George W. Bush', 'Bill Clinton', 'Uncle Bob'], $query->pluck('name'));
        $this->assert_same(3, $query->count());
        $this->assert_true($query->exists());
        $this->assert_same(ActiveRecord\MultipleRecordsFound::class, $this->thrown(fn() => $query->sole())[0] ?? null);

        $this->assert_same($sql, $query->to_sql());
        $this->assert_same($bindings, $query->get_bindings());
        $this->assert_same([2, 3, 4], $this->ids($query->get()));
    }

    // Spec §5.3: first() is the first of the query's rows, LIMIT 1, and sole() reads two, LIMIT 2. Both keep the
    // query's offset, and a smaller limit of its own: a query limited to no row has no first row.
    public function test_first_and_sole_read_the_window_of_the_query(): void
    {
        Author::table();

        $this->assert_same(2, Author::order_by('author_id')->offset(1)->first()->author_id);
        $this->assert_same(4, Author::order_by('author_id', 'desc')->first()->author_id);
        $this->assert_null(Author::order_by('author_id')->limit(0)->first());
        $this->assert_same(1, Author::order_by('author_id')->limit(1)->sole()->author_id);
        $this->assert_same(4, Author::order_by('author_id')->offset(3)->sole()->author_id);

        $authors = $this->sql('SELECT * FROM `authors` ORDER BY `author_id` ASC');
        $this->assert_same([$this->conn->limit($authors, null, 1)], array_column(GoldenStatements::capture(fn() => Author::order_by('author_id')->first()), 'sql'));
        $this->assert_same([$this->conn->limit($authors, null, 2)], array_column(GoldenStatements::capture(fn() => Author::order_by('author_id')->sole()), 'sql'));
        $this->assert_same([$this->conn->limit($authors, 3, 1)], array_column(GoldenStatements::capture(fn() => Author::order_by('author_id')->offset(3)->limit(5)->first()), 'sql'));

        // one id is found as first() finds a row; a list in the query's own window
        $this->assert_same([$this->conn->limit($this->sql('SELECT * FROM `authors` WHERE `author_id` = ?'), null, 1)], array_column(GoldenStatements::capture(fn() => Author::new_query()->find(1)), 'sql'));
        $this->assert_null(Author::new_query()->limit(0)->find(1));
        $this->assert_same([2], $this->ids(Author::order_by('author_id')->offset(1)->find_many([1, 2])));
    }

    // Spec §5.2: a find ANDs the key with the query's WHERE, which goes in parentheses, so that an OR in it never
    // widens the key, as in the legacy finders (#149). A WHERE that is one group in parentheses already is not
    // wrapped a second time: a closure's group, or the condition no row meets, which a raw '0' is written as.
    public function test_a_find_ands_the_key_with_the_where_in_parentheses(): void
    {
        $query = Author::where('name', 'Tito')->or_where('author_id', 3);

        $this->assert_null($query->find(2));
        $this->assert_same(3, $query->find(3)->author_id);
        $this->assert_same([1, 3], $this->ids($query->order_by('author_id')->find_many([1, 2, 3])));
        $this->assert_same(
            [['sql' => $this->sql('SELECT * FROM `authors` WHERE `author_id` IN(?,?) AND (`name` = ? OR `author_id` = ?)'), 'values' => [1, 2, 'Tito', 3]]],
            GoldenStatements::capture(fn() => Author::where('name', 'Tito')->or_where('author_id', 3)->find_many([1, 2]))
        );

        $group = Author::where(fn(Query $q) => $q->where('name', 'Tito')->or_where('author_id', 3));
        $this->assert_same(
            [$this->sql('SELECT * FROM `authors` WHERE `author_id` IN(?,?) AND (`name` = ? OR `author_id` = ?)')],
            array_column(GoldenStatements::capture(fn() => $group->find_many([1, 2])), 'sql')
        );
        $this->assert_same([1, 3], $this->ids($group->order_by('author_id')->find_many([1, 2, 3])));

        $compiler = new QueryCompiler(Author::table());
        $pk = ['sql' => $this->sql('`author_id` = ?'), 'values' => [1]];
        $this->assert_same(['sql' => $this->sql('`author_id` = ? AND (0)'), 'values' => [1]], $compiler->where_clause(Author::where_raw('0')->state(), [$pk]));
        // a negated group is wrapped, as everything else
        $this->assert_same(
            ['sql' => $this->sql('`author_id` = ? AND (NOT (`name` = ?))'), 'values' => [1, 'Tito']],
            $compiler->where_clause(Author::where_not(fn(Query $q) => $q->where('name', 'Tito'))->state(), [$pk])
        );
        $this->assert_same(
            ['sql' => $this->sql('`author_id` = ? AND ((`name` = ?) AND `author_id` > ?)'), 'values' => [1, 'Tito', 0]],
            $compiler->where_clause(Author::where(fn(Query $q) => $q->where('name', 'Tito'))->where('author_id', '>', 0)->state(), [$pk])
        );
    }

    // Spec §5.3: a find reads its ids as the legacy finders by pk do. A DATE pk takes the date of a DateTime, so
    // times of one day are one id. A value that an INTEGER pk can never equal is looked up in no statement, so on
    // Postgres it neither fails nor aborts the transaction (#92); it is missing, as on every database.
    public function test_a_find_reads_its_ids_as_the_legacy_finders_by_pk(): void
    {
        $this->assert_same(2, DatedDay::new_query()->find(new DateTime('2026-01-02 10:00:00'))->hits);
        $this->assert_same(['2026-01-02'], GoldenStatements::capture(fn() => DatedDay::new_query()->find(new DateTime('2026-01-02 10:00:00')))[0]['values']);
        $this->assert_same(
            [2],
            array_map(fn(DatedDay $day): int => $day->hits, DatedDay::new_query()->find_or_fail([new DateTime('2026-01-02 10:00:00'), new DateTimeImmutable('2026-01-02 23:59:59')]))
        );

        Author::transaction(function (): void {
            $this->assert_null(Author::new_query()->find('abc'));
            $this->assert_same([1], $this->ids(Author::new_query()->find_many([1, 'abc'])));
            $this->assert_same(
                [ActiveRecord\RecordNotFound::class, "Couldn't find all Author with IDs (1,abc) (found 1, but was looking for 2)"],
                $this->thrown(fn() => Author::new_query()->find_or_fail([1, 'abc']))
            );
            $this->assert_same('Tito', Author::new_query()->find(1)->name);
        });
    }

    // Spec §5.3: value(), sole_value() and pluck() cast a column of the model as a model casts its attribute
    // (Column::cast: a NUMERIC is a float, #146; a DATE an ActiveRecord\DateTime). A name that is no column of the
    // model by the rule of §5.1, a qualified one for instance, gives the driver's value. pluck() keys its map by the
    // driver's values, as PHP takes them for keys (a null is ''): a cast date could be no key. No model is built.
    public function test_the_values_of_a_column_are_cast_like_attributes_without_models(): void
    {
        $this->assert_same(0.0, Book::order_by('book_id')->value('special'));
        $day = DatedCount::order_by('day')->value('day');
        $this->assert_instance_of(ActiveRecord\DateTime::class, $day);
        $this->assert_same('2026-01-01', $day->format('Y-m-d'));
        $this->assert_same('2026-01-01', DatedCount::order_by('day')->value('dated_counts.day'));
        $this->assert_same([1, 2], DatedCount::order_by('day')->pluck('hits'));
        $this->assert_same(['2026-01-01' => 1, '2026-01-02' => 2], DatedCount::order_by('day')->pluck('hits', 'day'));
        $this->assert_same(['' => 'Uncle Bob'], Author::order_by('author_id')->pluck('name', 'some_text'));
        $this->assert_null(Author::where('author_id', 99)->value('name'));
        $this->assert_same([], Author::where('author_id', 99)->pluck('name'));

        // the column of an attribute name (#92) is cast as that attribute
        OddColumn::create(['some_day' => '2026-01-03']);
        $this->assert_same('2026-01-03', OddColumn::new_query()->value('some_day')->format('Y-m-d'));

        ConstructionCountingAuthor::$constructed = 0;
        ConstructionCountingAuthor::order_by('author_id')->pluck('name');
        ConstructionCountingAuthor::order_by('author_id')->pluck('name', 'author_id');
        ConstructionCountingAuthor::order_by('author_id')->value('name');
        ConstructionCountingAuthor::where('author_id', 1)->sole_value('name');
        $this->assert_same(0, ConstructionCountingAuthor::$constructed);
        $this->assert_same(4, count(ConstructionCountingAuthor::new_query()->get()));
        $this->assert_same(4, ConstructionCountingAuthor::$constructed);
    }

    // Spec §5.3: count() is the number of the query's rows, and with a column of those in which it is not null.
    // A plain query is counted in place, its order left out: it changes no number, and Postgres refuses it beside
    // COUNT. Grouped, distinct or windowed, the query is counted as a subquery, SELECT COUNT(*) FROM (…) AS t, and a
    // window keeps the order that picks its rows. The subquery selects 1 for a select of plain columns without an
    // alias, or none, when no column is counted and neither DISTINCT nor a HAVING needs the select: a select of every
    // column is refused beside a GROUP BY (Postgres, MySQL's ONLY_FULL_GROUP_BY), and one that gives two result columns
    // one name in a derived table by MySQL and MariaDB (1060), where get() runs. Otherwise it keeps the caller's select
    // (see test_count_and_exists_keep_the_select_a_clause_or_the_rows_depend_on), or with none the counted column.
    public function test_count_counts_the_rows_of_the_result(): void
    {
        // loaded first: a table read cold adds its schema read to a capture
        Author::table();
        Book::table();

        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(*) FROM `authors` WHERE `name` = ?'), 'values' => ['Tito']]],
            GoldenStatements::capture(fn() => Author::where('name', 'Tito')->order_by('name')->count())
        );
        $this->assert_same(1, Author::where('name', 'Tito')->order_by('name')->count());
        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(*) FROM (SELECT 1 FROM `authors` GROUP BY `parent_author_id`) AS t'), 'values' => []]],
            GoldenStatements::capture(fn() => Author::group_by('parent_author_id')->count())
        );
        $this->assert_same(1, Author::group_by('parent_author_id')->having_raw('COUNT(*) > ?', [1])->count());
        $this->assert_same(4, Author::distinct()->count());
        $this->assert_same(2, Book::joins('author')->count());

        // the caller's select gives two columns named name: the windowed count selects 1 instead
        $books = fn(): Query => Book::joins('author')->select('books.*', 'authors.name')->limit(5);
        $this->assert_same(2, count($books()->get()));
        $this->assert_same(2, $books()->count());
        $this->assert_sql_has('SELECT COUNT(*) FROM (SELECT 1 FROM books INNER JOIN', GoldenStatements::capture(fn() => $books()->count())[0]['sql']);
        // DISTINCT tells the rows apart by the caller's select, which stays
        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(*) FROM (') . $this->conn->limit($this->sql('SELECT DISTINCT `parent_author_id` FROM `authors`'), null, 10) . ') AS t', 'values' => []]],
            GoldenStatements::capture(fn() => Author::distinct()->select('parent_author_id')->limit(10)->count())
        );
        $this->assert_same(3, Author::distinct()->select('parent_author_id')->limit(10)->count());
        // a HAVING may name an alias of the caller's select, which stays (the alias itself: the adapter files)
        $having = fn(): Query => Author::select('parent_author_id')->select_raw('COUNT(*) AS n')->group_by('parent_author_id')->having_raw('COUNT(*) > ?', [1]);
        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(*) FROM (SELECT `parent_author_id`, COUNT(*) AS n FROM `authors` GROUP BY `parent_author_id` HAVING COUNT(*) > ?) AS t'), 'values' => [1]]],
            GoldenStatements::capture(fn() => $having()->count())
        );
        $this->assert_same(1, $having()->count());

        $this->conn->query("UPDATE authors SET some_text = 'x' WHERE author_id = 4");
        $this->assert_same(1, Author::new_query()->count('some_text'));
        $this->assert_same(4, Author::new_query()->count('author_id'));
        $this->assert_same(1, Author::order_by('author_id', 'desc')->limit(2)->count('some_text'));
        $this->assert_same(0, Author::order_by('author_id')->limit(2)->count('some_text'));
        $this->assert_same(
            [$this->sql('SELECT COUNT(t.`some_text`) FROM (') . $this->conn->limit($this->sql('SELECT `some_text` FROM `authors` ORDER BY `author_id` DESC'), null, 2) . ') AS t'],
            array_column(GoldenStatements::capture(fn() => Author::order_by('author_id', 'desc')->limit(2)->count('some_text')), 'sql')
        );

        // the counted column is t's column of the name the subquery gives it: the column of an attribute name
        // (#92), the last part of a qualified name
        OddColumn::create(['space_name' => 'a']);
        OddColumn::create(['camelcase' => 'b']);
        $this->assert_same(1, OddColumn::new_query()->count('space_name'));
        $this->assert_same(1, OddColumn::limit(10)->count('space_name'));
        $this->assert_same(2, Author::limit(2)->count('authors.author_id'));
    }

    // A count of a column under distinct() with no select of the query's own counts the column's distinct values, not
    // null: the subquery selects DISTINCT that column, Eloquent's COUNT(DISTINCT column), where it selected DISTINCT *
    // and counted every row a primary key keeps apart. A window stays on the subquery, so it picks values. The parents
    // of the authors are 3, 2, 1 and 2; the cities of the venues New York, Washington twice, West Chester, Richmond and
    // Hampton. A count of the rows, and a query with a select of its own, count as they did
    public function test_a_distinct_count_of_a_column_counts_its_distinct_values(): void
    {
        Author::table();

        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(t.`parent_author_id`) FROM (SELECT DISTINCT `parent_author_id` FROM `authors`) AS t'), 'values' => []]],
            GoldenStatements::capture(fn() => Author::distinct()->count('parent_author_id'))
        );
        $this->assert_same(3, Author::distinct()->count('parent_author_id'));
        $this->assert_same(3, Author::distinct()->count('authors.parent_author_id'));
        $this->assert_same(2, Author::distinct()->where('author_id', '>', 1)->count('parent_author_id'));
        $this->assert_same(2, Author::distinct()->limit(2)->count('parent_author_id'));
        $this->assert_same(5, Venue::distinct()->count('mycity'));
        $this->assert_same(6, Venue::new_query()->count('mycity'));

        $this->conn->query("UPDATE authors SET some_text = 'x' WHERE author_id IN (3, 4)");
        $this->assert_same(1, Author::distinct()->count('some_text'));
        $this->assert_same(2, Author::new_query()->count('some_text'));

        $this->assert_same(4, Author::distinct()->count());
        $this->assert_same(4, Author::distinct()->select('parent_author_id', 'name')->count('parent_author_id'));
    }

    // Spec §5.3: a wrapped count() and exists() keep the caller's select wherever a clause or the rows depend on it.
    // A GROUP BY and an ORDER BY can name an alias of it, on every database, and an aggregate in it collapses the rows
    // to one. So count() selects 1 only with no counted column, no DISTINCT, no HAVING, and a select of plain columns
    // without an alias (or none), and exists() only without a GROUP BY and a HAVING. The counts are those of get().
    public function test_count_and_exists_keep_the_select_a_clause_or_the_rows_depend_on(): void
    {
        Author::table();

        $by_alias = fn(): Query => Author::select_raw('parent_author_id AS p')->select_raw('COUNT(*) AS n')->group_by('p');
        $this->assert_same(3, count($by_alias()->get()));
        $this->assert_same(3, $by_alias()->count());
        $this->assert_true($by_alias()->exists());
        // grouped, an aggregate selects no row when no row matches
        $this->assert_false($by_alias()->where('name', 'Nobody')->exists());
        $this->assert_true($by_alias()->where('name', 'Nobody')->doesnt_exist());
        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(*) FROM (SELECT parent_author_id AS p, COUNT(*) AS n FROM `authors` GROUP BY `p`) AS t'), 'values' => []]],
            GoldenStatements::capture(fn() => $by_alias()->count())
        );
        $this->assert_same(
            [$this->conn->exists_sql($this->sql('SELECT parent_author_id AS p, COUNT(*) AS n FROM `authors` GROUP BY `p`'))],
            array_column(GoldenStatements::capture(fn() => $by_alias()->exists()), 'sql')
        );

        $ordered_by_alias = fn(): Query => Author::select('parent_author_id')->select_raw('COUNT(*) AS n')->group_by('parent_author_id')->order_by('n', 'desc')->limit(2);
        $this->assert_same(2, count($ordered_by_alias()->get()));
        $this->assert_same(2, $ordered_by_alias()->count());

        $shortest = fn(): Query => Author::select_raw('LENGTH(name) AS len')->order_by('len')->limit(2);
        $this->assert_same(2, count($shortest()->get()));
        $this->assert_same(2, $shortest()->count());

        // an aggregate collapses the rows to one, as get() returns it
        $none = fn(): Query => Author::select_raw('COUNT(*) AS n')->where('name', 'Nobody')->limit(5);
        $this->assert_same(1, count($none()->get()));
        $this->assert_same(1, $none()->count());
        $highest = fn(): Query => Author::select_raw('MAX(author_id) AS m')->limit(5);
        $this->assert_same(1, count($highest()->get()));
        $this->assert_same(1, $highest()->count());

        // a plain column selects 1 in the subquery; the same column under an alias keeps the select
        $window = $this->conn->limit($this->sql('SELECT 1 FROM `authors`'), null, 2);
        $this->assert_same([$this->sql('SELECT COUNT(*) FROM (') . $window . ') AS t'], array_column(GoldenStatements::capture(fn() => Author::select('name')->limit(2)->count()), 'sql'));
        $window = $this->conn->limit($this->sql('SELECT `name` AS `nm` FROM `authors`'), null, 2);
        $this->assert_same([$this->sql('SELECT COUNT(*) FROM (') . $window . ') AS t'], array_column(GoldenStatements::capture(fn() => Author::select('name as nm')->limit(2)->count()), 'sql'));
        $this->assert_same(2, Author::select('name as nm')->limit(2)->count());
    }

    // Spec §5.3: SQL written by hand in a GROUP BY, or in an ORDER BY the count keeps (a window's), can name the select
    // by position or expression, so a wrapped count() keeps even a plain select there. With 1 in its place, GROUP BY 1
    // groups by the constant and counts one group, and ORDER BY 2 passes the one column. An order the count leaves
    // out, without a window, is no reason to keep it. The counts are those of get().
    public function test_a_raw_group_or_kept_raw_order_keeps_the_select_of_a_count(): void
    {
        Author::table();

        $by_position = fn(): Query => Author::select('parent_author_id')->group_by_raw('1');
        $this->assert_same(3, count($by_position()->get()));
        $this->assert_same(3, $by_position()->count());
        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(*) FROM (SELECT `parent_author_id` FROM `authors` GROUP BY 1) AS t'), 'values' => []]],
            GoldenStatements::capture(fn() => $by_position()->count())
        );

        $ordered_by_position = fn(): Query => Author::select('name', 'author_id')->order_by_raw('2')->limit(2);
        $this->assert_same(2, count($ordered_by_position()->get()));
        $this->assert_same(2, $ordered_by_position()->count());

        // without a window the order is left out, and so is the select
        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(*) FROM (SELECT 1 FROM `authors` GROUP BY `name`) AS t'), 'values' => []]],
            GoldenStatements::capture(fn() => Author::select('name')->group_by('name')->order_by_raw('1')->count())
        );
        $this->assert_same(4, Author::select('name')->group_by('name')->order_by_raw('1')->count());
    }

    // ... and so does a query with no select of its own: spec §5.3 keeps the select-1 rule from SQL written by hand in the
    // GROUP BY, which can name a column of the select by position. The subquery selects what get() selects, so GROUP BY 1
    // groups by the first column of the table there too, and counts the four authors, where SELECT 1 ... GROUP BY 1
    // grouped by the constant and counted one group; and so does SQL written by hand in the ORDER BY a window keeps, where
    // ORDER BY 2 named a column that SELECT 1 does not have. A GROUP BY that names a column is counted as get() reads it:
    // its groups, or the refusal of MySQL and Postgres of a select of every column beside it, where get() is refused too
    public function test_a_raw_group_keeps_the_empty_select_of_a_count(): void
    {
        Author::table();

        $this->assert_same(4, count(Author::group_by_raw('1')->get()));
        $this->assert_same(4, Author::group_by_raw('1')->count());
        $this->assert_same(4, Author::group_by_raw('1')->having_raw('COUNT(*) = 1')->count());
        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(*) FROM (SELECT * FROM `authors` GROUP BY 1) AS t'), 'values' => []]],
            GoldenStatements::capture(fn() => Author::group_by_raw('1')->count())
        );
        $this->assert_same(4, Author::select('name')->group_by_raw('1')->count());

        // ORDER BY 2 of a window names the second column of the table, which SELECT 1 does not have
        $this->assert_same(2, count(Author::order_by_raw('2')->limit(2)->get()));
        $this->assert_same(2, Author::order_by_raw('2')->limit(2)->count());

        // the class of what get() and count() throw, or the number of rows they give: alike on every database
        $by_name = fn(): Query => Author::group_by_raw('parent_author_id');
        $rows = $this->thrown(fn() => $by_name()->get())[0] ?? count($by_name()->get());
        $counted = $this->thrown(fn() => $by_name()->count())[0] ?? $by_name()->count();
        $this->assert_same($rows, $counted);
        $this->assert_true(in_array($rows, [3, ActiveRecord\DatabaseException::class], true));

        if ($this->conn instanceof ActiveRecord\SqliteAdapter) {
            $this->assert_same(3, $counted);
        }

        $this->assert_same(3, Author::select('parent_author_id')->group_by_raw('parent_author_id')->count());
        $this->assert_same(
            [['sql' => $this->sql('SELECT COUNT(*) FROM (SELECT `parent_author_id` FROM `authors` GROUP BY parent_author_id) AS t'), 'values' => []]],
            GoldenStatements::capture(fn() => Author::select('parent_author_id')->group_by_raw('parent_author_id')->count())
        );
    }

    // Spec §5.3: exists() and doesnt_exist() leave out the order, the limit and the offset, and their values with
    // them, and without a GROUP BY and a HAVING select 1, as the legacy exists does: the caller's select could be an
    // aggregate, whose one row exists for no row. A GROUP BY or a HAVING, which may name an alias of it, keeps the
    // caller's select (grouped, an aggregate selects no row for no row); with none, it selects 1, since a select of
    // every column is refused beside a GROUP BY (Postgres, MySQL's ONLY_FULL_GROUP_BY).
    public function test_exists_leaves_out_the_order_and_the_window(): void
    {
        $this->assert_true(Author::order_by('name')->limit(0)->exists());
        $this->assert_true(Author::offset(4)->exists());
        $this->assert_true(Author::where('name', 'Nobody')->order_by('name')->limit(1)->doesnt_exist());
        $this->assert_false(Author::where('name', 'Tito')->doesnt_exist());
        $this->assert_true(Author::group_by('parent_author_id')->exists());

        $statements = GoldenStatements::capture(fn() => Author::where('author_id', '>', 1)->order_by_raw('CASE WHEN author_id = ? THEN 0 ELSE 1 END', [2])->limit(2)->offset(1)->exists());
        $this->assert_same([['sql' => $this->conn->exists_sql($this->sql('SELECT 1 FROM `authors` WHERE `author_id` > ?')), 'values' => [1]]], $statements);
        $this->assert_same(
            [$this->conn->exists_sql($this->sql('SELECT 1 FROM `authors`'))],
            array_column(GoldenStatements::capture(fn() => Author::select('name')->exists()), 'sql')
        );

        // an aggregate selects one row for no row: the rows are asked for, not it
        $this->assert_false(Author::select_raw('COUNT(*) AS n')->where('name', 'Nobody')->exists());
        $this->assert_true(Author::select_raw('COUNT(*) AS n')->where('name', 'Nobody')->doesnt_exist());
        $this->assert_true(Author::select_raw('COUNT(*) AS n')->where('name', 'Tito')->exists());

        // a HAVING keeps the caller's select (an alias of it in the HAVING: the adapter files)
        $having = fn(int $authors): Query => Author::select('parent_author_id')->group_by('parent_author_id')->having_raw('COUNT(*) > ?', [$authors]);
        $this->assert_same(
            [$this->conn->exists_sql($this->sql('SELECT `parent_author_id` FROM `authors` GROUP BY `parent_author_id` HAVING COUNT(*) > ?'))],
            array_column(GoldenStatements::capture(fn() => $having(1)->exists()), 'sql')
        );
        $this->assert_true($having(1)->exists());
        $this->assert_false($having(2)->exists());
        $this->assert_true($having(2)->doesnt_exist());
    }

    // Spec §5.7: an empty column name is refused at the call, before any statement is sent
    public function test_an_empty_column_name_is_refused(): void
    {
        Author::table();
        $refusals = [
            ['value', '', fn() => Author::new_query()->value('')],
            ['sole_value', ' ', fn() => Author::new_query()->sole_value(' ')],
            ['pluck', '', fn() => Author::new_query()->pluck('')],
            ['pluck', '', fn() => Author::new_query()->pluck('name', '')],
            ['count', '', fn() => Author::new_query()->count('')],
        ];

        foreach ($refusals as [$method, $name, $call]) {
            $this->assert_same(
                [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => "Empty column name: '$name' in $method() on Author"]],
                GoldenStatements::capture($call)
            );
        }
    }

    // Spec §6.3: the legacy find by pk still asks the model's connection(), the public static a subclass can
    // override, for its INTEGER pk check, once, as it always did; the builder reads through the Table's connection,
    // as its other terminals do
    public function test_the_legacy_find_by_key_still_asks_the_models_connection(): void
    {
        ConnectionCountingAuthor::table();

        ConnectionCountingAuthor::$connection_calls = 0;
        $this->assert_same(ActiveRecord\RecordNotFound::class, $this->thrown(fn() => ConnectionCountingAuthor::find(99))[0] ?? null);
        $this->assert_same(1, ConnectionCountingAuthor::$connection_calls);

        ConnectionCountingAuthor::$connection_calls = 0;
        $this->assert_null(ConnectionCountingAuthor::new_query()->find(99));
        $this->assert_same(0, ConnectionCountingAuthor::$connection_calls);
    }

    // The builder's terminals compile the builder's clauses: a query of legacy options (Query::from_options(), which
    // serves the legacy entries) refuses the key of a find rather than drop it
    public function test_a_query_of_legacy_options_refuses_the_key_of_a_find(): void
    {
        $this->assert_exception_message_contains(
            'holds both legacy options and builder clauses',
            fn() => Query::from_options(Author::table(), ['conditions' => ['name' => 'Tito']])->find_many([1, 2]),
            ActiveRecord\ActiveRecordException::class
        );
    }
}
