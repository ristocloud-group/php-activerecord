<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\Model;
use ActiveRecord\Query;
use ActiveRecord\SQLBuilder;

/**
 * The ordering, grouping, having and window methods of the query builder (spec §4.2, §5.2): order_by(),
 * order_by_desc(), order_by_raw(), latest(), oldest(), reorder() and in_random_order(); group_by() and
 * group_by_raw(); having(), or_having(), having_raw(), or_having_raw(), having_null(), having_not_null() and
 * having_between(); limit() and take(), offset() and skip(), and for_page().
 *
 * Fixture data: authors (author_id, parent_author_id, name) 1 (3) Tito, 2 (2) George W. Bush, 3 (1) Bill
 * Clinton, 4 (2) Uncle Bob, none with some_text or created_at, so that three groups of authors by their parent
 * are those of parent 1 (one author), parent 2 (two) and parent 3 (one); books 1 Ancient Art of Main Tanking by
 * author 1 and 2 Another Book by author 2; dated_counts (pk day DATE, at DATETIME) on 2026-01-01 and 2026-01-02.
 */
class QueryOrderGroupTest extends DatabaseTest
{
    /**
     * $sql with each backquote in the quote character of the connection.
     */
    private function sql(string $sql): string
    {
        return str_replace('`', $this->conn::$QUOTE_CHARACTER, $sql);
    }

    /**
     * The values of an attribute of the models, in the order the models came.
     *
     * @param list<Model> $models
     * @return list<mixed>
     */
    private function attributes(array $models, string $attribute = 'author_id'): array
    {
        return array_map(fn(Model $model): mixed => $model->$attribute, $models);
    }

    /**
     * The authors grouped by their parent, one model for each group, with its parent and the authors it counts, in
     * the order of the parents: the query every grouping test starts from.
     *
     * @return Query<Author>
     */
    private function by_parent(): Query
    {
        return Author::select('parent_author_id')->select_raw('COUNT(*) AS n')->group_by('parent_author_id')->order_by('parent_author_id');
    }

    /**
     * The parent and the count of each group of $query, as pairs.
     *
     * @param Query<Author> $query
     * @return list<array{int, int}>
     */
    private function pairs(Query $query): array
    {
        return array_map(fn(Model $group): array => [(int) $group->parent_author_id, (int) $group->n], $query->get());
    }

    /**
     * What follows the keyword of a clause in a statement, to the end of it (these queries have no later clause).
     */
    private function after(string $keyword, string $sql): string
    {
        return substr($sql, (int) strpos($sql, " $keyword ") + strlen($keyword) + 2);
    }

    public function test_order_forms(): void
    {
        $this->assert_sql_has('ORDER BY name ASC, author_id DESC', Author::order_by('name')->order_by_desc('author_id')->to_sql());
        $this->assert_sql_has('ORDER BY created_at DESC', Author::latest()->to_sql());
        $this->assert_sql_has('ORDER BY author_id ASC', Author::order_by('name')->reorder('author_id')->to_sql());
        $this->assert_exception_message_contains('direction', fn() => Author::order_by('name', 'sideways'), ActiveRecord\ActiveRecordException::class);
        $this->assert_sql_has('ORDER BY ' . Author::connection()->random_function(), Author::in_random_order()->to_sql());
    }

    public function test_group_having_and_window(): void
    {
        $q = Author::select('parent_author_id')->group_by('parent_author_id')->having('parent_author_id', '>', 1);
        $this->assert_sql_has('GROUP BY parent_author_id HAVING parent_author_id > ?', $q->to_sql());
        $this->assert_same([1], $q->get_bindings());
        $this->assert_same([3, 4], array_map(fn($a) => $a->author_id, Author::order_by('author_id')->for_page(2, 2)->get()));
        $this->assert_same([], Author::limit(0)->get());
        $this->assert_exception_message_contains('limit', fn() => Author::limit(-1), ActiveRecord\ActiveRecordException::class);
    }

    // -- order

    // asc and desc in any case are written in upper case, in the order they were added, after the other clauses
    // whatever the order of the calls, and sort the rows
    public function test_orders_are_written_in_upper_case_and_sort_the_rows(): void
    {
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `name` ASC'), Author::order_by('name')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `name` DESC, `author_id` ASC'), Author::order_by('name', 'DeSc')->order_by('author_id', 'ASC')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `name` DESC'), Author::order_by_desc('name')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `created_at` ASC'), Author::oldest()->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `created_at` DESC'), Author::latest()->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `author_id` ASC'), Author::oldest('author_id')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `author_id` DESC'), Author::latest('author_id')->to_sql());

        // the clauses are in the order of a SELECT, whatever the order of the calls
        $this->assert_same(
            $this->sql('SELECT `parent_author_id` FROM `authors` WHERE `author_id` > ? GROUP BY `parent_author_id` HAVING `parent_author_id` > ? ORDER BY `parent_author_id` DESC'),
            Author::order_by_desc('parent_author_id')->having('parent_author_id', '>', 1)->group_by('parent_author_id')->where('author_id', '>', 0)->select('parent_author_id')->to_sql()
        );

        $this->assert_same([4, 3, 2, 1], $this->attributes(Author::order_by_desc('author_id')->get()));
        $this->assert_same([3, 2, 1, 4], $this->attributes(Author::order_by('name')->get()));
        // parent 1, then 2 (the author with the greater id first), then 3
        $this->assert_same([3, 4, 2, 1], $this->attributes(Author::order_by('parent_author_id')->order_by_desc('author_id')->get()));
        $this->assert_same([4, 3, 2, 1], $this->attributes(Author::latest('author_id')->get()));
        $this->assert_same([1, 2, 3, 4], $this->attributes(Author::oldest('author_id')->get()));
    }

    // Spec §5.1: a column is named by the rule of every clause: an alias or an attribute name of the model is its
    // column, with joins a column of the model gets its table, and any other name, a joined table's, is as written
    public function test_orders_name_their_columns_by_the_rule_of_every_clause(): void
    {
        $this->assert_same($this->sql('SELECT * FROM `venues` ORDER BY `name` ASC, `city` DESC'), Venue::order_by('marquee')->order_by_desc('mycity')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `odd_columns` ORDER BY `space name` DESC'), OddColumn::latest('space_name')->to_sql());
        $this->assert_same(count(Venue::all()), count(Venue::order_by('marquee')->get()));

        $this->assert_sql_has('ORDER BY books.name ASC, authors.name DESC, books.book_id ASC', Book::joins('author')->order_by('name')->order_by_desc('authors.name')->oldest('book_id')->to_sql());
        $this->assert_sql_has('ORDER BY b.name ASC', Book::from('books', 'b')->order_by('name')->join('authors', 'authors.author_id', '=', 'b.author_id')->to_sql());
        $this->assert_same([2, 1], $this->attributes(Book::joins('author')->order_by_desc('books.book_id')->get(), 'book_id'));

        // only identifiers: an expression is one name, quoted, and never SQL
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `name; DROP TABLE authors` ASC'), Author::order_by('name; DROP TABLE authors')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `COUNT(*)` ASC'), Author::order_by('COUNT(*)')->to_sql());
    }

    // An order of SQL written by hand is sent as it is, in the place of its call among the orders, with the
    // values of its markers
    public function test_order_by_raw_is_sent_as_written_with_its_values(): void
    {
        $query = Author::order_by('name')->order_by_raw('CASE WHEN parent_author_id = ? THEN 0 ELSE 1 END, author_id * ? DESC', [2, 10]);

        $this->assert_sql_has('ORDER BY name ASC, CASE WHEN parent_author_id = ? THEN 0 ELSE 1 END, author_id * ? DESC', $query->to_sql());
        $this->assert_same([2, 10], $query->get_bindings());
        // the authors of parent 2 come first, the greater id first, and the others follow, the greater id first
        $this->assert_same([4, 2, 3, 1], $this->attributes(Author::order_by_raw('CASE WHEN parent_author_id = ? THEN 0 ELSE 1 END', [2])->order_by_desc('author_id')->get()));
        // none of its values: the SQL alone
        $this->assert_same([], Author::order_by_raw('author_id DESC')->get_bindings());
        $this->assert_same([4, 3, 2, 1], $this->attributes(Author::order_by_raw('author_id DESC')->get()));
    }

    public function test_in_random_order_uses_the_adapters_random_function(): void
    {
        $random = $this->conn->random_function();

        $this->assert_same($this->sql("SELECT * FROM `authors` ORDER BY $random"), Author::in_random_order()->to_sql());
        // an order like any other: it follows the orders before it
        $this->assert_same($this->sql("SELECT * FROM `authors` ORDER BY `name` ASC, $random"), Author::order_by('name')->in_random_order()->to_sql());
        $this->assert_same([], Author::in_random_order()->get_bindings());
        // it runs on the database: every row comes
        $ids = $this->attributes(Author::in_random_order()->get());
        sort($ids);
        $this->assert_same([1, 2, 3, 4], $ids);
    }

    // reorder() replaces every order, those written by hand and their values and the random one included, with
    // the one it is given, and without a column it leaves none; the other clauses stay
    public function test_reorder_replaces_every_order(): void
    {
        $query = Author::where('name', '!=', 'x')->order_by('name')->order_by_raw('author_id * ? DESC', [2])->in_random_order()->limit(3);

        $this->assert_same(['x', 2], $query->get_bindings());
        $this->assert_same($query, $query->reorder('author_id', 'DESC'));
        $this->assert_sql_has('WHERE name != ? ORDER BY author_id DESC', $query->to_sql());
        $this->assert_sql_doesnt_has('name ASC', $query->to_sql());
        $this->assert_sql_doesnt_has($this->conn->random_function(), $query->to_sql());
        $this->assert_same(['x'], $query->get_bindings());
        $this->assert_same([4, 3, 2], $this->attributes($query->get()));

        $this->assert_same($query, $query->reorder());
        $this->assert_sql_doesnt_has('ORDER BY', $query->to_sql());
        $this->assert_sql_has('WHERE name != ?', $query->to_sql());
        $this->assert_same(['x'], $query->get_bindings());
        $this->assert_same(3, count($query->get()));

        // the default direction is ascending, and a query that has no order is given one
        $this->assert_same($this->sql('SELECT * FROM `authors` ORDER BY `name` ASC'), Author::reorder('name')->to_sql());
        // the orders added after it follow it
        $this->assert_sql_has('ORDER BY name ASC, author_id DESC', Author::order_by('parent_author_id')->reorder('name')->order_by_desc('author_id')->to_sql());
    }

    // A refusal comes at the call that introduces the problem (spec §5.7), names the method, the model and the
    // input, and leaves the query as it was: reorder() refuses before it clears anything
    public function test_orders_refuse_what_they_cannot_read_and_leave_the_query_as_it_was(): void
    {
        $query = Author::order_by('name')->order_by_raw('author_id * ?', [3]);
        $before = [$query->to_sql(), $query->get_bindings()];

        foreach (['sideways', '', ' asc', 'asc ', 'ascending', 'asc; DROP TABLE authors', 'desc,'] as $direction) {
            foreach (['order_by' => fn() => $query->order_by('author_id', $direction), 'reorder' => fn() => $query->reorder('author_id', $direction)] as $method => $call) {
                $this->assert_exception_message_contains("Invalid order direction '$direction' in $method() on Author", $call, ActiveRecordException::class);
            }
        }

        // a direction no column goes with is refused as well
        $this->assert_exception_message_contains("Invalid order direction 'sideways' in reorder() on Author", fn() => $query->reorder(null, 'sideways'), ActiveRecordException::class);

        foreach ([
            'order_by' => fn() => $query->order_by(''),
            'order_by_desc' => fn() => $query->order_by_desc(''),
            'latest' => fn() => $query->latest(''),
            'oldest' => fn() => $query->oldest(''),
            'reorder' => fn() => $query->reorder(''),
        ] as $method => $call) {
            $this->assert_exception_message_contains("Empty column name: '' in $method() on Author", $call, ActiveRecordException::class);
        }

        $this->assert_same($before, [$query->to_sql(), $query->get_bindings()]);
    }

    // -- group

    public function test_group_by_groups_the_rows(): void
    {
        $query = $this->by_parent();

        $this->assert_same($this->sql('SELECT `parent_author_id`, COUNT(*) AS n FROM `authors` GROUP BY `parent_author_id` ORDER BY `parent_author_id` ASC'), $query->to_sql());
        $this->assert_same([[1, 1], [2, 2], [3, 1]], $this->pairs($query));
        $this->assert_same([], $query->get_bindings());

        // the columns of a call and of the calls that follow it, in order
        $this->assert_same($this->sql('SELECT * FROM `authors` GROUP BY `parent_author_id`, `name`, `author_id`'), Author::group_by('parent_author_id', 'name')->group_by('author_id')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors`'), Author::group_by()->to_sql());
    }

    public function test_group_by_names_its_columns_by_the_rule_of_every_clause(): void
    {
        $this->assert_same($this->sql('SELECT * FROM `venues` GROUP BY `name`, `city`'), Venue::group_by('marquee', 'mycity')->to_sql());
        $this->assert_sql_has('GROUP BY books.name, authors.name', Book::joins('author')->group_by('name', 'authors.name')->to_sql());
        $this->assert_same($this->sql('SELECT * FROM `authors` GROUP BY `name; DROP TABLE authors`'), Author::group_by('name; DROP TABLE authors')->to_sql());
    }

    public function test_group_by_raw_is_sent_as_written_with_its_values(): void
    {
        // the groups of the parent, whatever the second one, a constant, is
        $query = Author::select_raw('COUNT(*) AS n')->group_by('parent_author_id')->group_by_raw('CASE WHEN ? = 5 THEN 1 ELSE 0 END', [5])->order_by('n');

        $this->assert_same(
            $this->sql('SELECT COUNT(*) AS n FROM `authors` GROUP BY `parent_author_id`, CASE WHEN ? = 5 THEN 1 ELSE 0 END ORDER BY `n` ASC'),
            $query->to_sql()
        );
        $this->assert_same([5], $query->get_bindings());
        $this->assert_same([1, 1, 2], array_map(fn(Model $group): int => (int) $group->n, $query->get()));
        $this->assert_same([], Author::group_by_raw('parent_author_id')->get_bindings());
    }

    public function test_group_by_refuses_an_empty_name_and_adds_none_of_the_others(): void
    {
        $query = Author::group_by('name');

        $this->assert_exception_message_contains("Empty column name: '' in group_by() on Author", fn() => $query->group_by('author_id', ''), ActiveRecordException::class);
        $this->assert_same($this->sql('SELECT * FROM `authors` GROUP BY `name`'), $query->to_sql());
    }

    // -- having

    public function test_having_forms(): void
    {
        $query = $this->by_parent();

        $this->assert_same([[2, 2], [3, 1]], $this->pairs($this->by_parent()->having('parent_author_id', '>', 1)));
        // a column and a value are compared with =
        $this->assert_same([[2, 2]], $this->pairs($this->by_parent()->having('parent_author_id', 2)));
        $this->assert_same(
            $this->sql('SELECT `parent_author_id`, COUNT(*) AS n FROM `authors` GROUP BY `parent_author_id` HAVING `parent_author_id` = ? ORDER BY `parent_author_id` ASC'),
            $query->having('parent_author_id', 2)->to_sql()
        );
        $this->assert_same([2], $query->get_bindings());

        // ANDed, or ORed with or_having(); the first one's boolean is dropped
        $this->assert_same(
            $this->sql('SELECT * FROM `authors` GROUP BY `parent_author_id` HAVING `parent_author_id` > ? OR `parent_author_id` = ? AND `parent_author_id` != ?'),
            Author::group_by('parent_author_id')->having('parent_author_id', '>', 2)->or_having('parent_author_id', 1)->having('parent_author_id', '!=', 4)->to_sql()
        );
        $this->assert_same($this->sql('SELECT * FROM `authors` GROUP BY `parent_author_id` HAVING `parent_author_id` = ?'), Author::group_by('parent_author_id')->or_having('parent_author_id', 1)->to_sql());
        $this->assert_same([[1, 1], [3, 1]], $this->pairs($this->by_parent()->having('parent_author_id', 3)->or_having('parent_author_id', 1)));
    }

    // An expression is written by hand, with its values: in parentheses beside another condition, and a value that
    // is a list is spread over its marker, as in where_raw()
    public function test_having_raw_is_sent_as_written_with_its_values(): void
    {
        $query = $this->by_parent()->having_raw('COUNT(*) > ?', [1]);

        $this->assert_sql_has('GROUP BY parent_author_id HAVING COUNT(*) > ? ORDER BY', $query->to_sql());
        $this->assert_same([1], $query->get_bindings());
        $this->assert_same([[2, 2]], $this->pairs($query));

        $query = $this->by_parent()->having('parent_author_id', '>', 1)->having_raw('COUNT(*) > ?', [1]);
        $this->assert_sql_has('HAVING parent_author_id > ? AND (COUNT(*) > ?) ORDER BY', $query->to_sql());
        $this->assert_same([1, 1], $query->get_bindings());
        $this->assert_same([[2, 2]], $this->pairs($query));

        $query = $this->by_parent()->having_raw('COUNT(*) > ?', [1])->or_having_raw('parent_author_id = ?', [3]);
        $this->assert_sql_has('HAVING (COUNT(*) > ?) OR (parent_author_id = ?) ORDER BY', $query->to_sql());
        $this->assert_same([1, 3], $query->get_bindings());
        $this->assert_same([[2, 2], [3, 1]], $this->pairs($query));

        $query = $this->by_parent()->having_raw('parent_author_id IN(?)', [[1, 3]]);
        $this->assert_sql_has('HAVING parent_author_id IN(?,?) ORDER BY', $query->to_sql());
        $this->assert_same([1, 3], $query->get_bindings());
        $this->assert_same([[1, 1], [3, 1]], $this->pairs($query));
    }

    public function test_having_null_not_null_and_between(): void
    {
        $query = $this->by_parent()->having_between('parent_author_id', [2, 3]);

        $this->assert_sql_has('GROUP BY parent_author_id HAVING parent_author_id BETWEEN ? AND ? ORDER BY', $query->to_sql());
        $this->assert_same([2, 3], $query->get_bindings());
        $this->assert_same([[2, 2], [3, 1]], $this->pairs($query));

        // the keys of the list are not read
        $this->assert_same([2, 3], $this->by_parent()->having_between('parent_author_id', ['low' => 2, 'high' => 3])->get_bindings());

        $texts = fn(): Query => Author::select('some_text')->group_by('some_text');
        $this->assert_sql_has('GROUP BY some_text HAVING some_text IS NULL', $texts()->having_null('some_text')->to_sql());
        $this->assert_sql_has('GROUP BY some_text HAVING some_text IS NOT NULL', $texts()->having_not_null('some_text')->to_sql());
        // no author has some_text: the one group is that of null
        $this->assert_same(1, count($texts()->having_null('some_text')->get()));
        $this->assert_same(0, count($texts()->having_not_null('some_text')->get()));
        $this->assert_same([], $texts()->having_null('some_text')->get_bindings());
    }

    // A raw condition whose text is "0" is sent, as it is in the WHERE (see QueryWhereTest): SQLBuilder writes a HAVING
    // only when its text is truthy in PHP, so the builder gives it in parentheses. No group meets it on MySQL,
    // MariaDB and SQLite; Postgres takes no integer for a condition and refuses the statement.
    public function test_a_raw_having_of_zero_is_sent_and_meets_no_group(): void
    {
        $groups = fn(): Query => Author::select_raw('COUNT(*) AS n')->group_by('parent_author_id');
        $grouped = $this->sql('SELECT COUNT(*) AS n FROM `authors` GROUP BY `parent_author_id`');

        $this->assert_same("$grouped HAVING (0)", $groups()->having_raw('0')->to_sql());
        $this->assert_same("$grouped HAVING (0)", $groups()->or_having_raw('0')->to_sql());
        $this->assert_same($this->sql("$grouped HAVING `parent_author_id` > ? AND (0)"), $groups()->having('parent_author_id', '>', 1)->having_raw('0')->to_sql());
        $this->assert_same([], $groups()->having_raw('0')->get_bindings());

        if ($this->conn instanceof ActiveRecord\PgsqlAdapter) {
            $this->assert_exception_message_contains('argument of HAVING must be type boolean, not type integer', fn() => $groups()->having_raw('0')->get(), ActiveRecord\DatabaseException::class);
        } else {
            $this->assert_same([], $groups()->having_raw('0')->get());
            $this->assert_same([], $groups()->having('parent_author_id', '>', 1)->having_raw('0')->get());
        }

        // the same condition written as a comparison, which Postgres takes too, and a raw one that some groups meet
        $this->assert_same([], $groups()->having_raw('1 = 0')->get());
        $this->assert_same([1, 1, 2], array_map(fn(Model $group): int => (int) $group->n, $groups()->having_raw('COUNT(*) > ?', [0])->order_by('n')->get()));

        // the legacy 'having' => '0' leaves its clause out, as it always did: a known bug of the legacy options, kept so
        // that the legacy SQL stays byte-identical (the controller raises it as a candidate issue)
        $this->assert_same($this->sql('SELECT * FROM `authors` GROUP BY parent_author_id'), Author::table()->options_to_sql(['group' => 'parent_author_id', 'having' => '0'])->to_s());
    }

    // The conditions of a HAVING are read by Conditions and rendered by ConditionCompiler, as those of the WHERE are:
    // for the same arguments they are the same text and the same values (spec §3.2)
    public function test_having_conditions_are_those_of_where(): void
    {
        $pairs = [
            'column and value' => [fn(Query $q) => $q->where('name', 'x'), fn(Query $q) => $q->having('name', 'x')],
            'operator' => [fn(Query $q) => $q->where('author_id', '>=', 2), fn(Query $q) => $q->having('author_id', '>=', 2)],
            'operator in any case' => [fn(Query $q) => $q->where('name', 'NoT LiKe', 'x%'), fn(Query $q) => $q->having('name', 'NoT LiKe', 'x%')],
            'like' => [fn(Query $q) => $q->where('name', 'like', 'x%'), fn(Query $q) => $q->having('name', 'like', 'x%')],
            'ilike' => [fn(Query $q) => $q->where('name', 'ilike', 'x%'), fn(Query $q) => $q->having('name', 'ilike', 'x%')],
            'null' => [fn(Query $q) => $q->where('name', null), fn(Query $q) => $q->having('name', null)],
            'null with !=' => [fn(Query $q) => $q->where('name', '!=', null), fn(Query $q) => $q->having('name', '!=', null)],
            'null with <>' => [fn(Query $q) => $q->where('name', '<>', null), fn(Query $q) => $q->having('name', '<>', null)],
            'null with <=>' => [fn(Query $q) => $q->where('name', '<=>', null), fn(Query $q) => $q->having('name', '<=>', null)],
            'where_null' => [fn(Query $q) => $q->where_null('name'), fn(Query $q) => $q->having_null('name')],
            'where_not_null' => [fn(Query $q) => $q->where_not_null('name'), fn(Query $q) => $q->having_not_null('name')],
            'between' => [fn(Query $q) => $q->where_between('author_id', [1, 2]), fn(Query $q) => $q->having_between('author_id', [1, 2])],
            'raw' => [fn(Query $q) => $q->where_raw('author_id IN(?)', [[1, 2]]), fn(Query $q) => $q->having_raw('author_id IN(?)', [[1, 2]])],
            'or' => [fn(Query $q) => $q->where('name', 'x')->or_where('name', '<', 'y'), fn(Query $q) => $q->having('name', 'x')->or_having('name', '<', 'y')],
            'raw beside' => [
                fn(Query $q) => $q->where('name', 'x')->or_where_raw('a = ? OR b = ?', [1, 2]),
                fn(Query $q) => $q->having('name', 'x')->or_having_raw('a = ? OR b = ?', [1, 2]),
            ],
            'a date' => [
                fn(Query $q) => $q->where('some_Date', new DateTime('2026-01-02 10:00:00')),
                fn(Query $q) => $q->having('some_Date', new DateTime('2026-01-02 10:00:00')),
            ],
        ];

        foreach ($pairs as $name => [$where, $having]) {
            $in_where = $where(Author::new_query());
            $in_having = $having(Author::new_query());

            $this->assert_same($this->after('WHERE', $in_where->to_sql()), $this->after('HAVING', $in_having->to_sql()), $name);
            $this->assert_same($in_where->get_bindings(), $in_having->get_bindings(), $name);
        }

        // the LIKE operators compare the column as the adapter compares it in a LIKE: as text on Postgres
        $like = $this->conn->like_column($this->conn->quote_name('name'));
        $this->assert_sql_has("HAVING $like LIKE ?", Author::group_by('name')->having('name', 'like', 'T%')->to_sql());
    }

    // The same refusals as where(), at the call, naming having() and the model; a list of values has no
    // having_in(), so its message points at having_raw()
    public function test_having_refuses_what_where_refuses(): void
    {
        foreach ([
            ["Unknown operator 'bogus' in having() on Author", fn() => Author::having('parent_author_id', 'bogus', 1)],
            ["Unknown operator 'bogus' in or_having() on Author", fn() => Author::or_having('parent_author_id', 'bogus', 1)],
            ["Unknown operator 'x?' in having() on Author", fn() => Author::having('parent_author_id', 'x?', 1)],
            ["Illegal operator and value combination: '>' with null in having() on Author", fn() => Author::having('parent_author_id', '>', null)],
            ["No value given for 'parent_author_id' in having() on Author", fn() => Author::having('parent_author_id')],
            ["No value given for 'parent_author_id' in or_having() on Author", fn() => Author::or_having('parent_author_id')],
            ["An array is no value for '=' in having() on Author: a list of values goes to having_raw()", fn() => Author::having('parent_author_id', [1, 2])],
            ["An array is no value for '!=' in or_having() on Author: a list of values goes to having_raw()", fn() => Author::or_having('parent_author_id', '!=', [1, 2])],
            ["Empty column name: '' in having() on Author", fn() => Author::having('', 1)],
            ["Empty column name: '' in or_having() on Author", fn() => Author::or_having('', 1)],
            ["Empty column name: '' in having_null() on Author", fn() => Author::having_null('')],
            ["Empty column name: '' in having_not_null() on Author", fn() => Author::having_not_null('')],
            ["Empty column name: '' in having_between() on Author", fn() => Author::having_between('', [1, 2])],
            ['having_between() needs two values, not 1, on Author', fn() => Author::having_between('parent_author_id', [1])],
            ['An array is no value in the list given to having_between() on Author, at key 0', fn() => Author::having_between('parent_author_id', [[1], 2])],
        ] as [$message, $call]) {
            $this->assert_exception_message_contains($message, $call, ActiveRecordException::class);
        }

        // where() keeps its own hint
        $this->assert_exception_message_contains("An array is no value for '=' in where() on Author: a list of values goes to where_in() or where_not_in()", fn() => Author::where('author_id', [1, 2]), ActiveRecordException::class);
    }

    // Spec §5.1: the column of a condition is named by the rule of every clause
    public function test_having_names_its_columns_by_the_rule_of_every_clause(): void
    {
        $this->assert_same($this->sql('SELECT * FROM `venues` GROUP BY `name` HAVING `name` = ? AND `city` IS NOT NULL'), Venue::group_by('marquee')->having('marquee', 'x')->having_not_null('mycity')->to_sql());
        $this->assert_sql_has('GROUP BY books.name HAVING books.name = ? AND authors.name = ?', Book::joins('author')->group_by('name')->having('name', 'x')->having('authors.name', 'y')->to_sql());
    }

    // Spec §5.2: each value is prepared for the column it is compared with: a DateTime in the format of the
    // column's type, a date for a DATE column
    public function test_having_prepares_each_value_for_its_column(): void
    {
        $day = new DateTime('2026-01-02 10:00:00');
        $next = new DateTime('2026-01-03');

        $this->assert_same(['2026-01-02'], DatedCount::group_by('day')->having('day', $day)->get_bindings());
        $this->assert_same(['2026-01-02', '2026-01-03'], DatedCount::group_by('day')->having_between('day', [$day, $next])->get_bindings());
        $this->assert_same(1, count(DatedCount::select('day')->group_by('day')->having('day', $day)->get()));
    }

    // -- the bind values

    // Spec §3.2: the values of a SELECT come in the order of its clauses, whatever the order of the calls: the
    // select's, the FROM's, the joins', the WHERE's, the GROUP BY's, the HAVING's, then the ORDER BY's; and they are
    // the values get() sends, and exists() but for the ORDER BY's (spec §5.3: it leaves the order out)
    public function test_the_bindings_come_in_the_order_of_the_clauses_and_are_those_that_get_sends(): void
    {
        $query = Author::order_by_raw('CASE WHEN parent_author_id = ? THEN 0 ELSE 1 END', [2])
            ->having('parent_author_id', '>', 1)
            ->group_by_raw('CASE WHEN ? = 5 THEN 1 ELSE 0 END', [5])
            ->where('author_id', '>', 0)
            ->group_by('parent_author_id')
            ->select('parent_author_id')
            ->select_raw('COUNT(*) * ? AS scaled', [10]);

        $this->assert_same(
            $this->sql('SELECT `parent_author_id`, COUNT(*) * ? AS scaled FROM `authors` WHERE `author_id` > ? GROUP BY CASE WHEN ? = 5 THEN 1 ELSE 0 END, `parent_author_id` HAVING `parent_author_id` > ? ORDER BY CASE WHEN parent_author_id = ? THEN 0 ELSE 1 END'),
            $query->to_sql()
        );
        $this->assert_same([10, 0, 5, 1, 2], $query->get_bindings());

        $groups = [];
        $statements = GoldenStatements::capture(function () use ($query, &$groups): void {
            $groups = $query->get();
        });

        $this->assert_same([['sql' => $query->to_sql(), 'values' => $query->get_bindings()]], $statements);
        // the group of parent 2 (two authors) first, then that of parent 3
        $this->assert_same([20, 10], array_map(fn(Model $group): int => (int) $group->scaled, $groups));

        $exists = null;
        $statements = GoldenStatements::capture(function () use ($query, &$exists): void {
            $exists = $query->exists();
        });

        $this->assert_true($exists);
        $this->assert_same([[10, 0, 5, 1]], array_column($statements, 'values'));

        $sql = new SQLBuilder($this->conn, 'authors');
        $sql->where('name = ?', 'where')
            ->order_values(['order'])
            ->having_values(['having'])
            ->group_values(['group'])
            ->join_values(['join'])
            ->from_values(['from'])
            ->select_values(['select'])
            ->select('? AS a');

        $this->assert_same(['select', 'from', 'join', 'where', 'group', 'having', 'order'], $sql->select_bind_values());
        // the write of a statement binds its WHERE only
        $this->assert_same(['where'], $sql->get_where_values());
        $this->assert_same(['where'], $sql->bind_values());
    }

    // SQLBuilder gets additions only: the legacy methods keep their signatures, and the setters of the values are
    // internal, return the builder, and change nothing of the SQL
    public function test_sqlbuilder_keeps_its_legacy_signatures(): void
    {
        $signature = function (string $method): string {
            $reflection = new ReflectionMethod(SQLBuilder::class, $method);
            $parameters = array_map(
                fn(ReflectionParameter $parameter): string => ($parameter->hasType() ? $parameter->getType() . ' ' : '') . '$' . $parameter->getName(),
                $reflection->getParameters()
            );

            return $method . '(' . implode(', ', $parameters) . ')' . ($reflection->hasReturnType() ? ': ' . $reflection->getReturnType() : '');
        };

        foreach (['order($order)', 'group($group)', 'having($having)', 'limit($limit)', 'offset($offset)'] as $expected) {
            $this->assert_same($expected, $signature(explode('(', $expected)[0]));
        }

        foreach (['order_values', 'group_values', 'having_values'] as $method) {
            $this->assert_same($method . '(array $values): static', $signature($method));
            $this->assert_true(str_contains((string) (new ReflectionMethod(SQLBuilder::class, $method))->getDocComment(), '@internal'), "$method() is internal");
        }

        $sql = new SQLBuilder($this->conn, 'authors');
        $legacy = $sql->order('name')->group('parent_author_id')->having('COUNT(*) > 1')->to_s();
        $this->assert_same('SELECT * FROM authors GROUP BY parent_author_id HAVING COUNT(*) > 1 ORDER BY name', $legacy);
        $this->assert_same($legacy, $sql->order_values([])->group_values([])->having_values([])->to_s());
        $this->assert_same([], $sql->select_bind_values());
    }

    // -- the window

    // Spec §5.2: limit and offset go through the SQLBuilder calls of the legacy options, so the SQL is the legacy
    // SQL of each adapter, an offset without a limit (#130) and LIMIT 0 (#34) included; they are written in the
    // statement, never bound
    public function test_the_window_is_written_as_the_legacy_options_write_it(): void
    {
        $legacy = fn(array $options): string => Author::table()->options_to_sql($options)->to_s();

        $this->assert_same($legacy(['limit' => 2]), Author::limit(2)->to_sql());
        $this->assert_same($legacy(['limit' => 2]), Author::take(2)->to_sql());
        $this->assert_same($legacy(['offset' => 1]), Author::offset(1)->to_sql());
        $this->assert_same($legacy(['offset' => 1]), Author::skip(1)->to_sql());
        $this->assert_same($legacy(['limit' => 2, 'offset' => 1]), Author::limit(2)->offset(1)->to_sql());
        $this->assert_same($legacy(['limit' => 2, 'offset' => 1]), Author::skip(1)->take(2)->to_sql());
        $this->assert_same($legacy(['limit' => 0]), Author::limit(0)->to_sql());
        $this->assert_same($legacy(['limit' => 2, 'offset' => 2]), Author::for_page(2, 2)->to_sql());
        $this->assert_same($legacy(['limit' => 15, 'offset' => 0]), Author::for_page(1)->to_sql());
        $this->assert_same($legacy(['limit' => 15, 'offset' => 30]), Author::for_page(3)->to_sql());
        $this->assert_same($legacy(['limit' => 0, 'offset' => 0]), Author::for_page(1, 0)->to_sql());
        // the clauses that precede it: the window ends the statement
        $this->assert_same(
            $legacy(['conditions' => [$this->sql('`author_id` > ?'), 0], 'order' => $this->sql('`name` ASC'), 'limit' => 2, 'offset' => 1]),
            Author::limit(2)->offset(1)->order_by('name')->where('author_id', '>', 0)->to_sql()
        );
        $this->assert_same([], Author::limit(2)->offset(1)->get_bindings());
        $this->assert_same([], Author::for_page(2, 3)->get_bindings());
    }

    public function test_the_window_selects_the_rows(): void
    {
        $ids = fn(Query $query): array => $this->attributes($query->order_by('author_id')->get());

        $this->assert_same([1, 2], $ids(Author::limit(2)));
        $this->assert_same([1, 2], $ids(Author::take(2)));
        // an offset without a limit: every row after it (#130)
        $this->assert_same([2, 3, 4], $ids(Author::offset(1)));
        $this->assert_same([3, 4], $ids(Author::skip(2)));
        $this->assert_same([2, 3], $ids(Author::limit(2)->offset(1)));
        $this->assert_same([1, 2, 3, 4], $ids(Author::offset(0)));
        // LIMIT 0 finds nothing (#34), and so does an offset past the rows
        $this->assert_same([], $ids(Author::limit(0)));
        $this->assert_same([], $ids(Author::limit(0)->offset(2)));
        $this->assert_same([], $ids(Author::offset(4)));
        $this->assert_same([], $ids(Author::limit(2)->offset(9)));

        // the pages of three authors: the last holds the one that is left, and there is none beyond it
        $this->assert_same([1, 2, 3], $ids(Author::for_page(1, 3)));
        $this->assert_same([4], $ids(Author::for_page(2, 3)));
        $this->assert_same([], $ids(Author::for_page(3, 3)));
        $this->assert_same([1, 2], $ids(Author::for_page(1, 2)));
        $this->assert_same([1, 2, 3, 4], $ids(Author::for_page(1)));
        $this->assert_same([], $ids(Author::for_page(2)));
        // a page of no rows is no row
        $this->assert_same([], $ids(Author::for_page(1, 0)));

        // the last call that sets a number is the one that counts: for_page() sets both
        $this->assert_same([1], $ids(Author::limit(1)->offset(5)->for_page(1, 1)));
        $this->assert_same([3, 4], $ids(Author::limit(1)->offset(5)->for_page(2, 2)));
        $this->assert_same([2, 3], $ids(Author::for_page(2, 2)->limit(3)->offset(1)->limit(2)));
    }

    // The window is made of 64-bit integers: the largest ones are exact in the statement and the database takes them
    public function test_the_window_takes_the_largest_integers(): void
    {
        $max = (string) PHP_INT_MAX;

        $this->assert_sql_has($this->conn->limit('', null, PHP_INT_MAX), Author::limit(PHP_INT_MAX)->to_sql());
        $this->assert_same(4, count(Author::limit(PHP_INT_MAX)->get()));
        $this->assert_same([], Author::offset(PHP_INT_MAX)->get());
        $this->assert_same([], Author::limit(PHP_INT_MAX)->offset(PHP_INT_MAX)->get());
        $this->assert_sql_has($max, Author::offset(PHP_INT_MAX)->to_sql());

        // the page of the largest number that its offset takes: (PHP_INT_MAX - 1) * 1, exact
        $query = Author::for_page(PHP_INT_MAX, 1);
        $this->assert_sql_has((string) (PHP_INT_MAX - 1), $query->to_sql());
        $this->assert_same([], $query->get());
        // the offset (2^62 - 1) * 2 is the greatest even one
        $this->assert_sql_has((string) (PHP_INT_MAX - 1), Author::for_page(4611686018427387904, 2)->to_sql());
        // and the next page has none: its offset would be 2^63, which turns into a float
        $this->assert_exception_message_contains(
            'for_page() cannot start page 4611686018427387905 of 2 rows: the offset passes the largest integer, on Author',
            fn() => Author::for_page(4611686018427387905, 2),
            ActiveRecordException::class
        );
        $this->assert_exception_message_contains(
            'for_page() cannot start page ' . PHP_INT_MAX . ' of 15 rows: the offset passes the largest integer, on Author',
            fn() => Author::for_page(PHP_INT_MAX),
            ActiveRecordException::class
        );
    }

    // A negative number is refused at the call, naming the method as it was called, the model and the input; the
    // query is left as it was. for_page() names itself, never the offset() it stands for
    public function test_the_window_refuses_what_it_cannot_take(): void
    {
        foreach (['limit', 'take', 'offset', 'skip'] as $method) {
            foreach ([-1, PHP_INT_MIN] as $value) {
                $query = Author::limit(3)->offset(1);
                $before = $query->to_sql();

                $this->assert_exception_message_contains("$method() must be >= 0, not $value, on Author", fn() => $query->$method($value), ActiveRecordException::class);
                $this->assert_same($before, $query->to_sql());
            }
        }

        $query = Author::limit(3)->offset(1);
        $before = $query->to_sql();

        foreach ([
            ['for_page() needs a page >= 1, not 0, on Author', fn() => $query->for_page(0)],
            ['for_page() needs a page >= 1, not -3, on Author', fn() => $query->for_page(-3, 2)],
            ['for_page() needs a per_page >= 0, not -1, on Author', fn() => $query->for_page(1, -1)],
            ['for_page() needs a per_page >= 0, not -5, on Author', fn() => $query->for_page(4, -5)],
            // both wrong: the page first
            ['for_page() needs a page >= 1, not 0, on Author', fn() => $query->for_page(0, -1)],
        ] as [$message, $call]) {
            $this->assert_exception_message_contains($message, $call, ActiveRecordException::class);
        }

        $this->assert_same($before, $query->to_sql());

        // the message of the inner offset() is never surfaced
        try {
            $query->for_page(1, -1);
            $this->fail('for_page() took a negative per_page');
        } catch (ActiveRecordException $e) {
            $this->assert_false(str_contains($e->getMessage(), 'offset()'));
            $this->assert_false(str_contains($e->getMessage(), 'limit()'));
        }
    }

    // -- the state

    // A where() group holds where clauses only (spec §5.2): what the closure sets besides is refused by name
    public function test_a_where_group_holds_nothing_but_where_clauses(): void
    {
        foreach ([
            'orders' => fn(Query $q) => $q->order_by('name'),
            'groups' => fn(Query $q) => $q->group_by('name'),
            'havings' => fn(Query $q) => $q->having('name', 'x'),
            'limit' => fn(Query $q) => $q->limit(1),
            'offset' => fn(Query $q) => $q->offset(1),
        ] as $field => $group) {
            $this->assert_exception_message_contains("A where() group can only hold where clauses: the one given to where() on Author also holds $field", fn() => Author::where($group), ActiveRecordException::class);
        }

        // limit(0) and offset(0) are clauses too, though they are falsy
        $this->assert_exception_message_contains('also holds limit', fn() => Author::where(fn(Query $q) => $q->limit(0)), ActiveRecordException::class);
        $this->assert_exception_message_contains('also holds offset', fn() => Author::where(fn(Query $q) => $q->offset(0)), ActiveRecordException::class);
    }

    // A state holds legacy options or builder clauses, never both: the clauses of this task are clauses
    // whatever they hold, limit(0) and offset(0) included
    public function test_legacy_options_and_the_clauses_are_never_compiled_together(): void
    {
        $clauses = [
            'order_by' => fn(Query $q) => $q->order_by('name'),
            'order_by_raw' => fn(Query $q) => $q->order_by_raw('name'),
            'in_random_order' => fn(Query $q) => $q->in_random_order(),
            'group_by' => fn(Query $q) => $q->group_by('name'),
            'group_by_raw' => fn(Query $q) => $q->group_by_raw('name'),
            'having' => fn(Query $q) => $q->having('name', 'x'),
            'having_raw' => fn(Query $q) => $q->having_raw('name = 1'),
            'limit' => fn(Query $q) => $q->limit(2),
            'limit(0)' => fn(Query $q) => $q->limit(0),
            'offset' => fn(Query $q) => $q->offset(2),
            'offset(0)' => fn(Query $q) => $q->offset(0),
            'for_page' => fn(Query $q) => $q->for_page(1, 2),
        ];
        // loaded before the captures, which would otherwise hold the schema read of the table
        Author::table();

        foreach ($clauses as $name => $add) {
            $query = $add(Query::from_options(Author::table(), ['conditions' => ['name' => 'Tito']]));

            $this->assert_exception_message_contains('A query on Author holds both legacy options and builder clauses', fn() => $query->to_sql(), ActiveRecordException::class);

            // and the engine's writes, which read the legacy options only, refuse them: their WHERE would be dropped
            foreach (['UPDATE' => fn() => $add(Author::new_query())->execute_update(['name' => 'x']), 'DELETE' => fn() => $add(Author::new_query())->execute_delete()] as $operation => $write) {
                $this->assert_same(
                    [['exception' => ActiveRecordException::class, 'message' => "The $operation of a query on Author does not take builder clauses: it would drop them"]],
                    GoldenStatements::capture($write),
                    "$operation after $name"
                );
            }
        }

        $this->assert_same(4, Author::count());
    }

    // -- forwarding

    // Every method is reached statically (spec §4.1), runs, and returns the query it changes
    public function test_every_method_is_forwarded_and_returns_the_query(): void
    {
        $calls = [
            'order_by' => ['name'],
            'order_by_desc' => ['name'],
            'order_by_raw' => ['name DESC', []],
            'latest' => [],
            'oldest' => [],
            'reorder' => [],
            'in_random_order' => [],
            'group_by' => ['name'],
            'group_by_raw' => ['name'],
            'having' => ['name', 'x'],
            'or_having' => ['name', 'x'],
            'having_raw' => ['COUNT(*) > ?', [0]],
            'or_having_raw' => ['COUNT(*) > ?', [0]],
            'having_null' => ['name'],
            'having_not_null' => ['name'],
            'having_between' => ['name', ['a', 'b']],
            'limit' => [1],
            'take' => [1],
            'offset' => [1],
            'skip' => [1],
            'for_page' => [2, 2],
        ];

        $methods = array_values(array_filter(get_class_methods(Query::class), fn(string $method): bool => 1 === preg_match('/\A(order_by|latest|oldest|reorder|in_random_order|group_by|or_having|having|limit|take|offset|skip|for_page)/', $method)));
        $names = array_keys($calls);
        sort($methods);
        sort($names);
        $this->assert_same($names, $methods);

        foreach ($calls as $method => $arguments) {
            $this->assert_true(in_array($method, Query::FORWARDABLE, true), "$method() is forwardable");
            $this->assert_instance_of(Query::class, Author::$method(...$arguments), "Author::$method()");

            $query = Author::new_query();
            $this->assert_same($query, $query->$method(...$arguments), "$method() returns the query");
            $this->assert_true(is_string($query->to_sql()));
        }

        $this->assert_same([2, 3], $this->attributes(Author::order_by('author_id')->skip(1)->take(2)->get()));
    }

    // The adapter's random function (Connection::random_function()): RANDOM() by default, MySQL's own RAND()
    public function test_the_adapters_random_function(): void
    {
        $this->assert_same($this->conn instanceof ActiveRecord\MysqlAdapter ? 'RAND()' : 'RANDOM()', $this->conn->random_function());
        $this->assert_same('string', (string) (new ReflectionMethod(ActiveRecord\Connection::class, 'random_function'))->getReturnType());
    }
}
