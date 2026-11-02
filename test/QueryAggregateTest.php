<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\AggregateCaster;
use ActiveRecord\Query;

/**
 * The aggregates of the query builder (spec §5.4): min(), max(), sum(), avg() and average() of a column, read by the
 * type of the column. An exact integer is an int while it fits, else its digits; an exact decimal is the string the
 * database returns; a floating column is a float; the AVG of an exact column is exact. The adapter files pin what
 * differs by database (the raw types the adapter reports, the DECIMAL strings, SQLite's REAL storage).
 *
 * Fixture data: measurements 1 (qty 1, big 2^62, amount 1234567890123.45, ratio 0.1, label 'a') and 2 (qty 2, big
 * 2^62, amount 0.01, ratio 0.2, label 'b'). The fixture has no row of NULLs: the loader binds every CSV field as
 * text, which strict MySQL and Postgres refuse for a number. A test that needs one creates it (third_row(): qty 2,
 * label 'c', and NULL in big, amount and ratio). Also authors 1 to 4; books 1 and 2, of authors 1 and 2; book_reviews
 * of 5, 3 and 4 stars.
 */
class QueryAggregateTest extends DatabaseTest
{
    /**
     * $sql with each backtick replaced by the quote character of the connection.
     */
    private function sql(string $sql): string
    {
        return str_replace('`', $this->conn::$QUOTE_CHARACTER, $sql);
    }

    /**
     * The third measurement: qty 2, label 'c', and NULL in the other columns.
     */
    private function third_row(): void
    {
        Measurement::create(['qty' => 2, 'label' => 'c']);
    }

    /**
     * The statements that $call sent to the database, in order.
     *
     * @return list<string>
     */
    private function statements(callable $call): array
    {
        return array_column(GoldenStatements::capture($call), 'sql');
    }

    /**
     * What GoldenStatements::capture() gives for $method() of $column of $model, where the GROUP BY of the query does not group
     * the column and is not the whole primary key either (see QueryCompiler::refuse_ungrouped_column()): the exception, and no
     * statement before it.
     *
     * @return list<array{exception: class-string<\Throwable>, message: string}>
     */
    private function refusal(string $method, string $model, string $column): array
    {
        return [['exception' => ActiveRecordException::class, 'message' => "$method() on $model aggregates '$column', which the GROUP BY of the query does not group: MariaDB (default mode) and SQLite pick an arbitrary row per group; MySQL and Postgres refuse it unless the groups cover the primary key. Group by it, or give the query a select of its own"]];
    }

    /**
     * Gives the two measurements the values of big that the boundary tests sum.
     */
    private function set_big(int|string $first, int|string $second): void
    {
        foreach ([1 => $first, 2 => $second] as $id => $big) {
            $values = [$big, $id];
            $this->conn->query('UPDATE measurements SET big = ? WHERE id = ?', $values);
        }
    }

    // Spec §5.4, §10: the aggregated column is classified by its type and its raw type: DECIMAL covers the exact
    // types (decimal, numeric, dec) and the floating ones (float, double, real, double precision) alike. The adapter
    // files pin the strings each database reports.
    public function test_raw_types_classify_exact_and_floating(): void
    {
        $columns = Measurement::table()->columns;
        $this->assert_true(in_array($columns['amount']->raw_type, ['decimal', 'numeric'], true));
        $this->assert_true(in_array($columns['ratio']->raw_type, ['double', 'double precision', 'float', 'real'], true));

        // the kind of each column, where the database keeps the digits of a decimal; SQLite does not, and its DECIMAL is floating
        $exact = $this->conn->exact_decimals();
        $this->assert_same(
            ['id' => 'integer', 'qty' => 'integer', 'big' => 'integer', 'amount' => $exact ? 'decimal' : 'floating', 'ratio' => 'floating', 'single' => 'floating', 'label' => null],
            array_map(fn(ActiveRecord\Column $column): ?string => AggregateCaster::kind($column, $exact), $columns)
        );
    }

    public function test_integer_aggregates(): void
    {
        $this->third_row();
        $this->assert_same(5, Measurement::sum('qty'));
        $this->assert_same('1.6667', Measurement::avg('qty'));
        $this->assert_same(1, Measurement::min('qty'));
        $this->assert_same(2, Measurement::max('qty'));
        // average() is another name of avg()
        $this->assert_same('1.6667', Measurement::average('qty'));
        $this->assert_same('1.6667', Measurement::new_query()->average('qty'));
    }

    public function test_empty_sets(): void
    {
        $none = fn() => Measurement::where('label', 'zzz');
        $this->assert_same(0, $none()->sum('qty'));
        $this->assert_null($none()->avg('qty'));
        $this->assert_null($none()->min('qty'));
        $this->assert_same(0.0, $none()->sum('ratio'));

        // every kind: a SUM of no row is 0 of its type (that of the exact decimal is in the adapter files), a MIN, a MAX
        // and an AVG are null
        $this->assert_null($none()->max('qty'));
        $this->assert_null($none()->average('qty'));
        $this->assert_same(0, $none()->sum('big'));
        $this->assert_null($none()->min('big'));
        $this->assert_null($none()->max('big'));
        $this->assert_null($none()->avg('big'));
        $this->assert_null($none()->min('ratio'));
        $this->assert_null($none()->max('ratio'));
        $this->assert_null($none()->avg('ratio'));
        $this->assert_null($none()->min('amount'));
        $this->assert_null($none()->max('amount'));
        $this->assert_null($none()->avg('amount'));
        // a column that is no number gives the driver's value, which is NULL
        $this->assert_null($none()->max('label'));
        $this->assert_null($none()->min('label'));
    }

    // NULLs are not values: a MIN, a MAX and a SUM skip them, and the AVG divides by the count of the values (COUNT(col)),
    // not of the rows
    public function test_null_values_are_skipped(): void
    {
        $this->third_row();
        Measurement::create(['label' => 'n']);

        // qty 1, 2, 2 and NULL
        $this->assert_same(5, Measurement::sum('qty'));
        $this->assert_same('1.6667', Measurement::avg('qty'));
        $this->assert_same(1, Measurement::min('qty'));
        $this->assert_same('1.5000', Measurement::where_in('label', ['a', 'b', 'n'])->avg('qty'));
        // a SUM of nothing but NULLs is 0, a MIN, a MAX and an AVG of them are null
        $nulls = fn() => Measurement::where_in('label', ['c', 'n']);
        $this->assert_same(0, $nulls()->sum('big'));
        $this->assert_same(0.0, $nulls()->sum('ratio'));
        $this->assert_null($nulls()->max('big'));
        $this->assert_null($nulls()->avg('big'));
        $this->assert_null($nulls()->avg('ratio'));
        // big is 2^62 in one row and NULL in the other: the one value, not half of it
        $this->assert_same('4611686018427387904.0000', Measurement::where_in('label', ['a', 'c'])->avg('big'));
        $this->assert_same(4611686018427387904, Measurement::where_in('label', ['a', 'c'])->sum('big'));
        $this->assert_same(0.2, Measurement::where_in('label', ['b', 'c'])->avg('ratio'));
    }

    public function test_floating_aggregates_are_floats(): void
    {
        $this->assert_true(is_float(Measurement::sum('ratio')));
        $this->assert_true(is_float(Measurement::avg('ratio')));

        // the doubles of 0.1 and 0.2, and of their sum and mean (the sum of doubles is the database's: 0.1 + 0.2 is
        // 0.30000000000000004, whichever way it adds)
        $this->assert_same(0.1, Measurement::min('ratio'));
        $this->assert_same(0.2, Measurement::max('ratio'));
        $this->assert_true(abs(Measurement::sum('ratio') - 0.3) < 1e-12);
        $this->assert_true(abs(Measurement::avg('ratio') - 0.15) < 1e-12);
        $this->assert_true(is_float(Measurement::min('ratio')) && is_float(Measurement::max('ratio')));
        $this->assert_true(abs(Measurement::average('ratio') - 0.15) < 1e-12);
    }

    // A single-precision column is floating whatever its raw type says: FLOAT on MySQL and MariaDB (a Column::DECIMAL), real on
    // Postgres and REAL on SQLite, which the type mapping has no entry for, so they are a Column::STRING with the raw type real.
    // The values are exact in single precision, so every adapter gives the same floats (PHP 8.3's Postgres driver gives them
    // as text, PHP 8.5's as floats).
    public function test_a_single_precision_column_is_floating(): void
    {
        $this->conn->query('UPDATE measurements SET single = 0.5 WHERE id = 1');
        $this->conn->query('UPDATE measurements SET single = 0.25 WHERE id = 2');

        $this->assert_same(0.75, $this->strict_call(fn() => Measurement::sum('single')));
        $this->assert_same(0.25, $this->strict_call(fn() => Measurement::min('single')));
        $this->assert_same(0.5, $this->strict_call(fn() => Measurement::max('single')));
        $this->assert_same(0.375, $this->strict_call(fn() => Measurement::avg('single')));
        $this->assert_same(0.375, $this->strict_call(fn() => Measurement::average('single')));
        $this->assert_same(0.0, $this->strict_call(fn() => Measurement::where('label', 'zzz')->sum('single')));
        $this->assert_null(Measurement::where('label', 'zzz')->max('single'));
        $this->assert_null(Measurement::where('label', 'zzz')->avg('single'));
        $this->assert_same(0.5, $this->strict_call(fn() => Measurement::order_by('id')->limit(1)->sum('single')));
    }

    // A float never reaches ExactDecimal, whose int|string parameters PHP would give an int for it (1.5 is 1, with a
    // deprecation). A floating column is a float whatever the adapter gives (PHP 8.3's Postgres driver gives a float8 as a
    // string) and is never read as an exact one, which would raise the deprecation, or refuse the text. Any diagnostic
    // fails here.
    public function test_floating_aggregates_raise_no_php_diagnostic(): void
    {
        $this->third_row();

        foreach (['min', 'max', 'sum', 'avg', 'average'] as $method) {
            $value = $this->strict_call(fn() => Measurement::$method('ratio'));
            $this->assert_true(is_float($value), "$method(ratio) is " . get_debug_type($value));
        }

        $this->assert_same(0.2, $this->strict_call(fn() => Measurement::max('ratio')));
        $this->assert_same(0.0, $this->strict_call(fn() => Measurement::where('label', 'zzz')->sum('ratio')));
    }

    // Spec §5.4, last column: a column that is not the model's names no type, so its aggregates are the driver's. The MIN and the
    // MAX of an INT are an int on every adapter; the SUM and the AVG are typed by each (MySQL's SUM is a DECIMAL string,
    // Postgres's AVG a numeric string, SQLite's AVG a float), and each adapter file pins its own.
    public function test_non_model_column_returns_the_driver_value(): void
    {
        $this->assert_same(1, Book::joins('author')->min('authors.author_id'));
        $this->assert_same(2, Book::joins('author')->max('authors.author_id'));
        // no row: the driver's NULL, where a column of the model has a 0 of its type
        $none = fn(): Query => Book::joins('author')->where('books.book_id', 99);
        $this->assert_null($none()->sum('authors.author_id'));
        $this->assert_null($none()->min('authors.author_id'));
        $this->assert_null($none()->avg('authors.author_id'));
    }

    // A column of another type than a number (text, a date) is the driver's value too: the text of a date, not a DateTime
    public function test_a_column_that_is_no_number_gives_the_driver_value(): void
    {
        $this->third_row();
        $this->assert_same('a', Measurement::min('label'));
        $this->assert_same('c', Measurement::max('label'));
        $this->assert_same('b', Measurement::where('label', 'b')->min('label'));

        // dated_counts: 2026-01-01 10:00:00 and 2026-01-02 10:00:00
        $this->assert_same('2026-01-01', DatedCount::min('day'));
        $this->assert_same('2026-01-02', DatedCount::max('day'));
        $this->assert_same('2026-01-02 10:00:00', DatedCount::max('at'));
    }

    // Spec §5.1: the aggregated column is named by the one rule of the builder: an unqualified column of the model gets
    // the model's table when there are joins, so that author_id, a column of books and of authors, is not ambiguous
    public function test_a_column_of_the_model_is_named_by_the_one_rule_of_the_builder(): void
    {
        $this->assert_same(2, Book::joins('author')->max('author_id'));
        $this->assert_sql_has('SELECT MAX(books.author_id) FROM books INNER JOIN', Book::table()->last_sql);
        $this->assert_same(3, Book::joins('author')->sum('author_id'));
        $this->assert_sql_has('SELECT SUM(books.author_id) FROM books INNER JOIN', Book::table()->last_sql);
        $this->assert_same('1.5000', Book::joins('author')->avg('author_id'));
        $this->assert_sql_has('SELECT SUM(books.author_id), COUNT(books.author_id) FROM books INNER JOIN', Book::table()->last_sql);
        // a qualified name is quoted part by part, as written
        $this->assert_same(1, Book::joins('author')->min('books.author_id'));
        $this->assert_same(4, Author::max('author_id'));
    }

    // Spec §5.1: an alias names its column, and the column's type is the type of the aggregate (score is rating, an INT)
    public function test_an_alias_names_its_column_and_the_type_of_the_column_reads_the_aggregate(): void
    {
        AliasedBookReview::table();

        $this->assert_same(3, AliasedBookReview::min('score'));
        $this->assert_same(5, AliasedBookReview::max('score'));
        $this->assert_same('4.0000', AliasedBookReview::avg('score'));
        $this->assert_same('4.0000', AliasedBookReview::average('score'));
        $this->assert_sql_has('SELECT SUM(rating), COUNT(rating) FROM book_reviews', AliasedBookReview::table()->last_sql);
        $this->assert_same(12, AliasedBookReview::sum('score'));
        $this->assert_sql_has('SELECT SUM(rating) FROM book_reviews', AliasedBookReview::table()->last_sql);
    }

    // Each aggregate is one statement over the query's FROM, joins and WHERE, without its order (Postgres refuses an order
    // by a column beside an aggregate). An exact column's AVG is its SUM and its COUNT, whatever the adapter: the
    // database's own AVG is never asked, and a floating column's is.
    public function test_each_aggregate_is_one_statement(): void
    {
        // loaded first: a table read cold adds its schema read to a capture
        Measurement::table();

        $this->assert_same([$this->sql('SELECT MIN(`qty`) FROM `measurements`')], $this->statements(fn() => Measurement::min('qty')));
        $this->assert_same([$this->sql('SELECT MAX(`qty`) FROM `measurements`')], $this->statements(fn() => Measurement::max('qty')));
        $this->assert_same([$this->sql('SELECT SUM(`qty`) FROM `measurements`')], $this->statements(fn() => Measurement::sum('qty')));
        $this->assert_same([$this->sql('SELECT SUM(`ratio`) FROM `measurements`')], $this->statements(fn() => Measurement::sum('ratio')));

        // an exact decimal too, where the database keeps its digits (SQLite stores a REAL: its average is the database's)
        $exact = ['qty' => true, 'amount' => $this->conn->exact_decimals(), 'ratio' => false];

        foreach ($exact as $column => $is_exact) {
            $expected = $is_exact ? "SELECT SUM(`$column`), COUNT(`$column`) FROM `measurements`" : "SELECT AVG(`$column`) FROM `measurements`";

            foreach (['avg', 'average'] as $method) {
                $this->assert_same([$this->sql($expected)], $this->statements(fn() => Measurement::$method($column)), "$method($column)");
            }
        }

        $this->assert_same(
            [['sql' => $this->sql('SELECT SUM(`qty`) FROM `measurements` WHERE `label` = ?'), 'values' => ['a']]],
            GoldenStatements::capture(fn() => Measurement::where('label', 'a')->order_by('id')->sum('qty'))
        );
        $this->assert_same(
            [['sql' => $this->sql('SELECT SUM(`qty`), COUNT(`qty`) FROM `measurements` WHERE `label` = ?'), 'values' => ['a']]],
            GoldenStatements::capture(fn() => Measurement::where('label', 'a')->order_by('id')->avg('qty'))
        );
    }

    // Spec §5.3's rule for count(), for the aggregates too: the aggregate of the rows of the result. A query that is
    // windowed, grouped or distinct is aggregated as a subquery, SELECT SUM(t.col) FROM (…) AS t, where a plain
    // aggregate would take the LIMIT as one of its single row, and the GROUP BY as one group of its own.
    public function test_a_windowed_grouped_or_distinct_query_aggregates_its_rows(): void
    {
        Measurement::table();
        $this->third_row();

        // qty of the rows in order: 1, 2, 2
        $by_id = fn(): Query => Measurement::order_by('id');
        $this->assert_same(5, $by_id()->sum('qty'));
        $this->assert_same(3, $by_id()->limit(2)->sum('qty'));
        $this->assert_same(4, $by_id()->offset(1)->sum('qty'));
        $this->assert_same(1, $by_id()->limit(1)->max('qty'));
        $this->assert_same(2, $by_id()->limit(2)->offset(1)->min('qty'));
        $this->assert_same('1.5000', $by_id()->limit(2)->avg('qty'));
        $this->assert_same('2.0000', $by_id()->limit(2)->offset(1)->average('qty'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`qty`) FROM (') . $this->conn->limit($this->sql('SELECT `qty` FROM `measurements` ORDER BY `id` ASC'), null, 2) . ') AS t'],
            $this->statements(fn() => $by_id()->limit(2)->sum('qty'))
        );
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`qty`), COUNT(t.`qty`) FROM (') . $this->conn->limit($this->sql('SELECT `qty` FROM `measurements` ORDER BY `id` ASC'), null, 2) . ') AS t'],
            $this->statements(fn() => $by_id()->limit(2)->avg('qty'))
        );

        // a window of no row has no value: the rows of the result are none
        $this->assert_same(0, $by_id()->limit(0)->sum('qty'));
        $this->assert_null($by_id()->limit(0)->max('qty'));
        $this->assert_null($by_id()->limit(0)->avg('qty'));
        $this->assert_same(0.0, $by_id()->limit(0)->sum('ratio'));

        // distinct: the values of the distinct rows of the select
        $this->assert_same(3, Measurement::distinct()->select('qty')->sum('qty'));
        $this->assert_same('1.5000', Measurement::distinct()->select('qty')->avg('qty'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`qty`) FROM (SELECT DISTINCT `qty` FROM `measurements`) AS t')],
            $this->statements(fn() => Measurement::distinct()->select('qty')->sum('qty'))
        );

        // grouped: the rows are the groups', whose column must be grouped
        $this->assert_same(3, Measurement::group_by('qty')->sum('qty'));
        $this->assert_same(2, Measurement::group_by('qty')->having_raw('COUNT(*) > ?', [1])->sum('qty'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`qty`) FROM (SELECT `qty` FROM `measurements` GROUP BY `qty`) AS t')],
            $this->statements(fn() => Measurement::group_by('qty')->sum('qty'))
        );

        // the caller's select stays where an alias of it is the aggregated column: no model column is named doubled, so the
        // driver's values (the adapter files pin the types of the SUM and the AVG, which differ)
        $doubled = fn(): Query => Measurement::select_raw('qty * 2 AS doubled')->order_by('id')->limit(10);
        $this->assert_same(4, $doubled()->max('doubled'));
        $this->assert_same(2, $doubled()->min('doubled'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`doubled`) FROM (') . $this->conn->limit($this->sql('SELECT qty * 2 AS doubled FROM `measurements` ORDER BY `id` ASC'), null, 10) . ') AS t'],
            $this->statements(fn() => $doubled()->sum('doubled'))
        );
    }

    // With distinct() and no select of the query's own, the aggregate of a column runs over the column's distinct values:
    // the subquery selects DISTINCT that column, where it selected DISTINCT *, every row, which a primary key keeps apart.
    // So sum() and avg() are Eloquent's SUM(DISTINCT col) and AVG(DISTINCT col), avg exact at the SUM's scale plus 4 as
    // always, and count() of the column its COUNT(DISTINCT col); min() and max() are the same either way, and count() of the
    // rows is unchanged. A window stays on the subquery, where it picks values, and a select of the query's own is
    // aggregated as before, over the distinct rows it gives
    public function test_a_distinct_aggregate_of_a_column_runs_over_its_distinct_values(): void
    {
        Measurement::table();
        $this->third_row();

        // qty: 1, 2, 2
        $this->assert_same(3, Measurement::distinct()->sum('qty'));
        $this->assert_same('1.5000', Measurement::distinct()->avg('qty'));
        $this->assert_same('1.5000', Measurement::distinct()->average('qty'));
        $this->assert_same(1, Measurement::distinct()->min('qty'));
        $this->assert_same(2, Measurement::distinct()->max('qty'));
        $this->assert_same(2, Measurement::distinct()->count('qty'));
        $this->assert_same(3, Measurement::distinct()->count());
        $this->assert_same([5, '1.6667'], [Measurement::new_query()->sum('qty'), Measurement::new_query()->avg('qty')]);
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`qty`), COUNT(t.`qty`) FROM (SELECT DISTINCT `qty` FROM `measurements`) AS t')],
            $this->statements(fn() => Measurement::distinct()->avg('qty'))
        );
        $this->assert_same(
            [$this->sql('SELECT MAX(t.`qty`) FROM (SELECT DISTINCT `qty` FROM `measurements`) AS t')],
            $this->statements(fn() => Measurement::distinct()->max('qty'))
        );

        // a qualified name (the driver's value, which no model column reads: a string on MySQL and MariaDB), a where, a window
        $this->assert_same(3, (int) Measurement::distinct()->sum('measurements.qty'));
        $this->assert_same(2, Measurement::distinct()->where('id', '>', 1)->sum('qty'));
        $this->assert_same(1, Measurement::distinct()->order_by('qty')->limit(1)->sum('qty'));

        // an alias attribute: score is rating, 5, 3 and 4, and 3 again
        AliasedBookReview::create(['book_id' => 2, 'rating' => 3]);
        $this->assert_same(12, AliasedBookReview::distinct()->sum('score'));
        $this->assert_same('4.0000', AliasedBookReview::distinct()->avg('score'));
        $this->assert_same(15, AliasedBookReview::new_query()->sum('score'));

        // a select of its own: the distinct rows of qty and label are the three rows
        $this->assert_same(5, Measurement::distinct()->select('qty', 'label')->sum('qty'));
    }

    // The subquery of such an aggregate is SELECT DISTINCT of the column alone, and a window (a limit or an offset) keeps
    // the order of the query there, where it picks the values. An order by another column cannot go with it: MySQL (3065)
    // and Postgres (42P10) refuse the statement, and MariaDB and SQLite pick the values arbitrarily. It is refused at the
    // call, before any statement, by count() of a column and the aggregates. An order by the aggregated column, by any of
    // its names, picks the values; without a window the order is dropped; and a select of the query's own, a count of the
    // rows and SQL of order_by_raw(), which is not read, go to the database as before
    public function test_a_distinct_aggregate_refuses_a_window_ordered_by_another_column(): void
    {
        Measurement::table();
        Book::table();
        // a relationship names its target class with a leading backslash, under which Table keeps another instance of the table
        ActiveRecord\Table::load('\\Author');
        $this->third_row();
        $refusal = fn(string $method, string $model, string $column, string $order): array => [[
            'exception' => ActiveRecordException::class,
            'message' => "$method() on $model aggregates the distinct values of '$column' in a window ordered by '$order', another column: SELECT DISTINCT of one column cannot be ordered by another (MySQL and Postgres refuse it, and MariaDB and SQLite pick the values arbitrarily). Order by '$column', or give the query a select of its own",
        ]];

        // qty of the rows in order: 1, 2, 2
        foreach ([
            'sum' => [fn() => Measurement::distinct()->order_by('id')->limit(2)->sum('qty'), 'sum', 'Measurement', 'qty', 'id'],
            'an offset alone' => [fn() => Measurement::distinct()->order_by('id')->offset(1)->sum('qty'), 'sum', 'Measurement', 'qty', 'id'],
            'avg' => [fn() => Measurement::distinct()->order_by('label')->limit(2)->avg('qty'), 'avg', 'Measurement', 'qty', 'label'],
            'average' => [fn() => Measurement::distinct()->order_by('label')->limit(2)->average('qty'), 'average', 'Measurement', 'qty', 'label'],
            'min' => [fn() => Measurement::distinct()->order_by_desc('id')->limit(1)->min('qty'), 'min', 'Measurement', 'qty', 'id'],
            'max' => [fn() => Measurement::distinct()->latest('id')->limit(1)->max('qty'), 'max', 'Measurement', 'qty', 'id'],
            'count of the column' => [fn() => Measurement::distinct()->order_by('id')->limit(2)->count('qty'), 'count', 'Measurement', 'qty', 'id'],
            'a second order' => [fn() => Measurement::distinct()->order_by('qty')->order_by('id')->limit(2)->sum('qty'), 'sum', 'Measurement', 'qty', 'id'],
            "a joined table's column" => [fn() => Book::distinct()->joins('author')->order_by('authors.name')->limit(1)->sum('book_id'), 'sum', 'Book', 'book_id', 'authors.name'],
        ] as $case => [$call, $method, $model, $column, $order]) {
            $this->assert_same($refusal($method, $model, $column, $order), GoldenStatements::capture($call), $case);
        }

        // the aggregated column, by its name, qualified, through an alias, under a from() alias: the window picks its values
        $this->assert_same(1, Measurement::distinct()->order_by('qty')->limit(1)->sum('qty'));
        $this->assert_same(1, Measurement::distinct()->order_by('qty')->offset(1)->count('qty'));
        $this->assert_same(2, Measurement::distinct()->order_by_desc('measurements.qty')->limit(1)->min('qty'));
        $this->assert_same(1, (int) Measurement::distinct()->order_by('qty')->limit(1)->sum('measurements.qty'));
        $this->assert_same(1, Measurement::from('measurements', 'm')->distinct()->order_by('m.qty')->limit(1)->sum('qty'));
        // score is rating: 5, 3 and 4, the two smallest 3 and 4
        $this->assert_same(7, AliasedBookReview::distinct()->order_by('rating')->limit(2)->sum('score'));
        $this->assert_same(7, AliasedBookReview::distinct()->order_by('score')->limit(2)->sum('rating'));

        // no window: the order is dropped
        $this->assert_same(3, Measurement::distinct()->order_by('id')->sum('qty'));
        $this->assert_same([$this->sql('SELECT SUM(t.`qty`) FROM (SELECT DISTINCT `qty` FROM `measurements`) AS t')], $this->statements(fn() => Measurement::distinct()->order_by('id')->sum('qty')));
        // a select of its own (the distinct rows of qty and id, the first two), a count of the rows, and SQL that is not read
        $this->assert_same(3, Measurement::distinct()->select('qty', 'id')->order_by('id')->limit(2)->sum('qty'));
        $this->assert_same(2, Measurement::distinct()->order_by('id')->limit(2)->count());
        $this->assert_same(2, Measurement::distinct()->order_by_raw('qty DESC')->limit(1)->sum('qty'));
    }

    // MariaDB (in its default mode) and SQLite run an aggregate of a column that the GROUP BY does not group, and read it from
    // an arbitrary row of each group: parent 2 has the authors 2 and 4, so the MAX of author_id is 3 where it is 4, and the SUM
    // is 6 where it is 10. MySQL (1055) and Postgres (42803) refuse the statement, unless the groups are the whole primary key
    // (see test_groups_over_the_whole_primary_key_let_any_column_be_aggregated). min(), max(), sum() and avg() refuse the
    // column at the call, on every database and before any statement, when the query has no select of its own and only
    // columns of the model in its groups.
    public function test_a_grouped_query_refuses_a_column_it_does_not_group(): void
    {
        Author::table();
        AliasedBookReview::table();
        Book::table();
        // a relationship names its target class with a leading backslash, under which Table keeps another instance of the table
        ActiveRecord\Table::load('\\Author');

        foreach (['min', 'max', 'sum', 'avg', 'average'] as $method) {
            $this->assert_same(
                $this->refusal($method, 'Author', 'author_id'),
                GoldenStatements::capture(fn() => Author::group_by('parent_author_id')->$method('author_id')),
                $method
            );
        }

        // whatever else the query has: a HAVING, a window, an order, a DISTINCT, a WHERE, a second group that is not it
        $refused = [
            'having' => fn(): Query => Author::group_by('parent_author_id')->having_raw('COUNT(*) > ?', [1]),
            'window' => fn(): Query => Author::group_by('parent_author_id')->order_by('parent_author_id')->limit(2),
            'where' => fn(): Query => Author::where('name', '!=', 'Tito')->group_by('parent_author_id'),
            'distinct' => fn(): Query => Author::distinct()->group_by('parent_author_id'),
            'two groups' => fn(): Query => Author::group_by('parent_author_id', 'name'),
            'two calls' => fn(): Query => Author::group_by('parent_author_id')->group_by('name'),
        ];

        foreach ($refused as $case => $query) {
            $captured = GoldenStatements::capture(fn() => $query()->sum('author_id'));
            $this->assert_same($this->refusal('sum', 'Author', 'author_id'), $captured, $case);
        }

        // an alias names its column: score is rating, and reviewed_book is book_id, the column that is grouped
        $this->assert_same(
            $this->refusal('sum', 'AliasedBookReview', 'score'),
            GoldenStatements::capture(fn() => AliasedBookReview::group_by('reviewed_book')->sum('score'))
        );
        // with joins, the model's columns are told by name and not by their table
        $this->assert_same(
            $this->refusal('max', 'Book', 'book_id'),
            GoldenStatements::capture(fn() => Book::joins('author')->group_by('author_id')->max('book_id'))
        );
    }

    // A column that the query groups is aggregated over its groups, by whichever name: the groups of parent_author_id are 1, 2
    // and 3 (the parents of the four authors are 3, 2, 1 and 2), and those of book_id in the reviews 1 and 2
    public function test_a_grouped_column_is_aggregated_over_its_groups(): void
    {
        Author::table();

        $parents = fn(): Query => Author::group_by('parent_author_id');
        $this->assert_same(6, $parents()->sum('parent_author_id'));
        $this->assert_same(1, $parents()->min('parent_author_id'));
        $this->assert_same(3, $parents()->max('parent_author_id'));
        $this->assert_same('2.0000', $parents()->avg('parent_author_id'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`parent_author_id`) FROM (SELECT `parent_author_id` FROM `authors` GROUP BY `parent_author_id`) AS t')],
            $this->statements(fn() => $parents()->sum('parent_author_id'))
        );
        // one of several groups (a group for each author: 3 + 2 + 1 + 2), and with a HAVING (parent 2 has two authors)
        $this->assert_same(8, Author::group_by('name', 'parent_author_id')->sum('parent_author_id'));
        $this->assert_same(2, Author::group_by('parent_author_id')->having_raw('COUNT(*) > ?', [1])->sum('parent_author_id'));

        // by an alias or an attribute name, which both name the column
        $reviews = fn(): Query => AliasedBookReview::group_by('reviewed_book');
        $this->assert_same(3, $reviews()->sum('book_id'));
        $this->assert_same(3, $reviews()->sum('reviewed_book'));
        $this->assert_same(2, AliasedBookReview::group_by('book_id')->max('reviewed_book'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`book_id`) FROM (SELECT `book_id` FROM `book_reviews` GROUP BY `book_id`) AS t')],
            $this->statements(fn() => $reviews()->sum('reviewed_book'))
        );

        // with joins, the column of the model is the grouped one
        $this->assert_same(2, Book::joins('author')->group_by('author_id')->max('author_id'));
        $this->assert_same(3, Book::joins('author')->group_by('author_id')->sum('author_id'));
    }

    // Groups that are the whole primary key are one row of the table each, and every other column of it depends on them. MySQL
    // (1055) and Postgres (42803) refuse a column that depends on nothing grouped, and this one does, so every database runs
    // the statement and none reads a row of its own choice: no column is refused, by whichever names the key is given. The
    // parents of the four authors are 3, 2, 1 and 2; dated_counts is keyed by (day, at), and its hits are 1 and 2.
    public function test_groups_over_the_whole_primary_key_let_any_column_be_aggregated(): void
    {
        Author::table();
        Book::table();
        DatedCount::table();
        AliasedDatedCount::table();

        $authors = fn(): Query => Author::group_by('author_id');
        $this->assert_same(8, $authors()->sum('parent_author_id'));
        $this->assert_same(3, $authors()->max('parent_author_id'));
        $this->assert_same(1, $authors()->min('parent_author_id'));
        $this->assert_same('2.0000', $authors()->avg('parent_author_id'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`parent_author_id`) FROM (SELECT `parent_author_id` FROM `authors` GROUP BY `author_id`) AS t')],
            $this->statements(fn() => $authors()->sum('parent_author_id'))
        );

        // the key with another group, in either order; with a HAVING, which every group of one row meets; with a window, the
        // first two authors (parents 3 and 2)
        $this->assert_same(8, Author::group_by('name', 'author_id')->sum('parent_author_id'));
        $this->assert_same(3, Author::group_by('author_id', 'name')->max('parent_author_id'));
        $this->assert_same(8, $authors()->having_raw('COUNT(*) = ?', [1])->sum('parent_author_id'));
        $this->assert_same(5, $authors()->order_by('author_id')->limit(2)->sum('parent_author_id'));

        // with joins, the key and the column are the model's, and an exact decimal is as the database keeps it (SQLite stores a
        // REAL, and its sum is a float)
        foreach ([1 => '1.50', 2 => '2.25'] as $id => $special) {
            $values = [$special, $id];
            $this->conn->query('UPDATE books SET special = ? WHERE book_id = ?', $values);
        }

        $exact = $this->conn->exact_decimals();
        $books = fn(): Query => Book::joins('author')->group_by('book_id');
        $this->assert_same($exact ? '3.75' : 3.75, $books()->sum('special'));
        $this->assert_sql_has('SELECT SUM(t.special) FROM (SELECT books.special FROM books INNER JOIN', Book::table()->last_sql);
        $this->assert_sql_has('GROUP BY books.book_id) AS t', Book::table()->last_sql);
        $this->assert_same($exact ? '2.25' : 2.25, $books()->max('special'));
        $this->assert_same($exact ? '1.50' : 1.5, $books()->min('special'));

        // a composite key is covered whole, in either order, and an alias of one of its columns is that column
        $this->assert_same(3, DatedCount::group_by('day', 'at')->sum('hits'));
        $this->assert_same(2, DatedCount::group_by('at', 'day')->max('hits'));
        $this->assert_same(3, AliasedDatedCount::group_by('happened_on', 'at')->sum('hits'));
    }

    // Less than the whole key is no key: a column that is not the key, however different its values are (the four authors' names
    // are all different), tells the database nothing about the others, nor does a part of a composite key, by its name or by an
    // alias, nor does any group of a table that has no key. MariaDB and SQLite would read an arbitrary row of each group, so the
    // column is refused as before.
    public function test_groups_that_are_not_the_whole_primary_key_still_refuse_the_other_columns(): void
    {
        Author::table();
        DatedCount::table();
        AliasedDatedCount::table();
        PklessItem::table();

        $this->assert_same(
            $this->refusal('sum', 'Author', 'parent_author_id'),
            GoldenStatements::capture(fn() => Author::group_by('name')->sum('parent_author_id'))
        );

        // dated_counts is keyed by (day, at)
        $this->assert_same(
            $this->refusal('sum', 'DatedCount', 'hits'),
            GoldenStatements::capture(fn() => DatedCount::group_by('day')->sum('hits'))
        );
        $this->assert_same(
            $this->refusal('max', 'DatedCount', 'hits'),
            GoldenStatements::capture(fn() => DatedCount::group_by('at')->max('hits'))
        );
        $this->assert_same(
            $this->refusal('max', 'DatedCount', 'at'),
            GoldenStatements::capture(fn() => DatedCount::group_by('day')->max('at'))
        );
        $this->assert_same(
            $this->refusal('sum', 'AliasedDatedCount', 'hits'),
            GoldenStatements::capture(fn() => AliasedDatedCount::group_by('happened_on')->sum('hits'))
        );

        // pkless_items has no key
        $this->assert_same(
            $this->refusal('sum', 'PklessItem', 'code'),
            GoldenStatements::capture(fn() => PklessItem::group_by('name')->sum('code'))
        );
    }

    // Spec §5.3: SQL written by hand in the GROUP BY, or in the ORDER BY a window keeps, can name a column of the select by
    // position, so the subquery of an aggregate with no select of its own selects what get() selects there, and not the
    // aggregated column alone: GROUP BY 1 grouped by that column, and summed its distinct values (6), and ORDER BY 1 of a
    // window picked the rows of its smallest values (3). The numbers are those of get()'s rows
    public function test_a_raw_group_or_kept_raw_order_keeps_the_empty_select_of_an_aggregate(): void
    {
        Author::table();

        // GROUP BY 1 groups by the first column of what get() selects, the key: each author is a group
        $this->assert_same(4, count(Author::group_by_raw('1')->get()));
        $this->assert_same(8, Author::group_by_raw('1')->sum('parent_author_id'));
        $this->assert_same(3, Author::group_by_raw('1')->max('parent_author_id'));
        $this->assert_same(4, Author::group_by_raw('1')->count('parent_author_id'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`parent_author_id`) FROM (SELECT * FROM `authors` GROUP BY 1) AS t')],
            $this->statements(fn() => Author::group_by_raw('1')->sum('parent_author_id'))
        );

        // ORDER BY 1 of a window orders by the key, and picks authors 1 and 2, whose parents are 3 and 2
        $this->assert_same([3, 2], array_map(fn(Author $author): int => $author->parent_author_id, Author::order_by_raw('1')->limit(2)->get()));
        $this->assert_same(5, Author::order_by_raw('1')->limit(2)->sum('parent_author_id'));

        // a select of the query's own is what the positions name
        $this->assert_same(6, Author::select('parent_author_id')->group_by_raw('1')->sum('parent_author_id'));

        // a GROUP BY that names a column is aggregated as get() reads it: its groups, or the refusal of MySQL and Postgres
        // of a select of every column beside it, where get() is refused too
        $by_name = fn(): ActiveRecord\Query => Author::group_by_raw('parent_author_id');
        $thrown = function (callable $call): ?string {
            try {
                $call();
            } catch (Throwable $e) {
                return $e::class;
            }

            return null;
        };
        $read = $thrown(fn() => $by_name()->get());
        $this->assert_same($read ?? 3, $thrown(fn() => $by_name()->max('parent_author_id')) ?? $by_name()->max('parent_author_id'));
        $this->assert_true(in_array($read, [null, ActiveRecord\DatabaseException::class], true));
        $this->assert_same(3, Author::select('parent_author_id')->group_by_raw('parent_author_id')->max('parent_author_id'));
    }

    // What cannot be told goes to the database as it is, with the residual that the database decides: a select of the query's
    // own (which can aggregate the column, or alias it), SQL of group_by_raw() and a qualified name, which this does not read.
    // The statement is sent in every case (MySQL and Postgres may refuse it, MariaDB and SQLite read an arbitrary row), and
    // count() never checks its column.
    public function test_what_cannot_be_told_is_not_refused_and_nor_is_count(): void
    {
        Author::table();

        $this->assert_same(
            $this->sql('SELECT MAX(t.`author_id`) FROM (SELECT `author_id` FROM `authors` GROUP BY `authors`.`parent_author_id`) AS t'),
            GoldenStatements::capture(fn() => Author::group_by('authors.parent_author_id')->max('author_id'))[0]['sql']
        );
        $this->assert_same(
            $this->sql('SELECT MAX(t.`author_id`) FROM (SELECT * FROM `authors` GROUP BY parent_author_id) AS t'),
            GoldenStatements::capture(fn() => Author::group_by_raw('parent_author_id')->max('author_id'))[0]['sql']
        );
        $this->assert_same(
            $this->sql('SELECT MAX(t.`author_id`) FROM (SELECT `parent_author_id`, `author_id` FROM `authors` GROUP BY `parent_author_id`) AS t'),
            GoldenStatements::capture(fn() => Author::select('parent_author_id', 'author_id')->group_by('parent_author_id')->max('author_id'))[0]['sql']
        );
        // nor is the aggregated column, when it is a name that no column of the model has (a qualified one, a joined table's)
        $this->assert_same(
            $this->sql('SELECT MAX(t.`author_id`) FROM (SELECT `authors`.`author_id` FROM `authors` GROUP BY `parent_author_id`) AS t'),
            GoldenStatements::capture(fn() => Author::group_by('parent_author_id')->max('authors.author_id'))[0]['sql']
        );
        $this->assert_same(
            $this->sql('SELECT COUNT(t.`author_id`) FROM (SELECT `author_id` FROM `authors` GROUP BY `parent_author_id`) AS t'),
            GoldenStatements::capture(fn() => Author::group_by('parent_author_id')->count('author_id'))[0]['sql']
        );
    }

    // A select of its own that aggregates per group: the aggregate of the groups' values, which every database runs
    public function test_a_select_of_its_own_aggregates_the_values_it_gives(): void
    {
        $maxima = fn(): Query => Author::select_raw('parent_author_id, MAX(author_id) AS m')->group_by('parent_author_id');

        // the highest author of each parent: 1 for parent 3, 4 for parent 2, 3 for parent 1
        $this->assert_same(4, $maxima()->max('m'));
        $this->assert_same(1, $maxima()->min('m'));
        $this->assert_same(
            [$this->sql('SELECT MAX(t.`m`) FROM (SELECT parent_author_id, MAX(author_id) AS m FROM `authors` GROUP BY `parent_author_id`) AS t')],
            $this->statements(fn() => $maxima()->max('m'))
        );
    }

    // An alias attribute names its column in a wrapped query too: the subquery selects the real column, and t's column of that
    // name is aggregated, as the column's type reads it (score is rating, an INT: 5, 3 and 4 in the order of the reviews)
    public function test_an_alias_attribute_in_a_wrapped_query(): void
    {
        AliasedBookReview::table();

        $reviews = fn(): Query => AliasedBookReview::order_by('id');
        $this->assert_same(8, $reviews()->limit(2)->sum('score'));
        $this->assert_same(3, $reviews()->limit(2)->min('score'));
        $this->assert_same(5, $reviews()->limit(2)->max('score'));
        $this->assert_same('4.0000', $reviews()->limit(2)->avg('score'));
        $this->assert_same(7, $reviews()->offset(1)->sum('score'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`rating`) FROM (') . $this->conn->limit($this->sql('SELECT `rating` FROM `book_reviews` ORDER BY `id` ASC'), null, 2) . ') AS t'],
            $this->statements(fn() => $reviews()->limit(2)->sum('score'))
        );

        // distinct, with the alias in the select, and the attribute name of the column in the aggregate, or the other way round
        $this->assert_same(12, AliasedBookReview::distinct()->select('score')->sum('score'));
        $this->assert_same(12, AliasedBookReview::distinct()->select('score')->sum('rating'));
        $this->assert_same(12, AliasedBookReview::distinct()->select('rating')->sum('score'));
        $this->assert_same(
            [$this->sql('SELECT SUM(t.`rating`) FROM (SELECT DISTINCT `rating` FROM `book_reviews`) AS t')],
            $this->statements(fn() => AliasedBookReview::distinct()->select('score')->sum('score'))
        );
    }

    // A named column of a model, whatever the window: its type reads the aggregate of the subquery as well
    public function test_a_windowed_query_reads_the_aggregate_by_the_type_of_the_column(): void
    {
        $by_id = fn(): Query => Measurement::order_by('id');

        $this->assert_same(4611686018427387904, $by_id()->limit(1)->sum('big'));
        $this->assert_same('4611686018427387904.0000', $by_id()->limit(1)->avg('big'));
        $this->assert_same(0.1, $by_id()->limit(1)->max('ratio'));
        $this->assert_same(2, $by_id()->limit(1)->offset(1)->max('qty'));
    }

    // A DateTime bound in SQL written by hand is formatted as the other terminals format theirs (Table::format_date_bind_values(),
    // #138): PDO cannot bind the object. dated_counts has 1 hit at 2026-01-01 10:00:00 and 2 at 2026-01-02 10:00:00.
    public function test_a_date_time_bound_in_raw_sql_is_formatted_as_the_other_terminals_format_it(): void
    {
        $at = fn(): Query => DatedCount::where_raw('at = ?', [new DateTime('2026-01-02 10:00:00')]);

        $this->assert_same(1, $at()->count());
        $this->assert_same(2, $at()->sum('hits'));
        $this->assert_same(2, $at()->max('hits'));
        $this->assert_same(2, $at()->min('hits'));
        $this->assert_same('2.0000', $at()->avg('hits'));
    }

    // An aggregate reads the query as it stands and leaves it so: the state is the query's, the statements are copies
    public function test_an_aggregate_leaves_the_query_as_it_was(): void
    {
        $query = Measurement::where('qty', '>', 0)->order_by('id')->limit(5)->select('qty', 'ratio');
        $before = [$query->to_sql(), $query->get_bindings()];

        $query->sum('qty');
        $query->min('qty');
        $query->max('qty');
        $query->avg('qty');
        $query->average('ratio');

        $this->assert_same($before, [$query->to_sql(), $query->get_bindings()]);
        $this->assert_same(2, count($query->get()));
        $this->assert_same(2, $query->count());
        $this->assert_same(3, $query->sum('qty'));
    }

    // The column's name is checked before any statement, as the other builder terminals do theirs: no name, and * (which
    // is COUNT(*)'s alone), are refused at the call
    public function test_the_column_name_is_checked_before_any_statement(): void
    {
        Measurement::table();

        foreach (['min', 'max', 'sum', 'avg', 'average'] as $method) {
            $this->assert_same(
                [['exception' => ActiveRecordException::class, 'message' => "Empty column name: '' in $method() on Measurement"]],
                GoldenStatements::capture(fn() => Measurement::$method('')),
                "$method('')"
            );
            $this->assert_same(
                [['exception' => ActiveRecordException::class, 'message' => "Empty column name: '  ' in $method() on Measurement"]],
                GoldenStatements::capture(fn() => Measurement::new_query()->$method('  ')),
                "$method('  ')"
            );
            $this->assert_same(
                [['exception' => ActiveRecordException::class, 'message' => "$method() takes a column name, not '*', on Measurement"]],
                GoldenStatements::capture(fn() => Measurement::$method('*')),
                "$method('*')"
            );
        }
    }

    // The five names are forwarded to a new query of the class (spec §4.1), and none is a method of Model
    public function test_the_aggregates_are_forwarded_from_the_class(): void
    {
        foreach (['min', 'max', 'sum', 'avg', 'average'] as $name) {
            $this->assert_true(in_array($name, Query::FORWARDABLE, true), $name);
            $this->assert_false(method_exists(ActiveRecord\Model::class, $name), $name);
        }

        $this->assert_same(Measurement::new_query()->where('label', 'b')->sum('qty'), Measurement::where('label', 'b')->sum('qty'));
        $this->assert_same(2, Measurement::where('label', 'b')->sum('qty'));
    }

    // "Exact 64-bit correctness": a SUM of BIGINTs is an int up to PHP_INT_MAX and down to PHP_INT_MIN, as are the MIN and
    // the MAX, and its AVG is exact, on every adapter (SQLite's too: its SUM of integers is an int). Beyond that range a
    // SUM is the exact digits (the MySQL, MariaDB and Postgres files) or SQLite's integer overflow. The expected values
    // are those of exact integer arithmetic (Python).
    public function test_a_sum_of_bigints_is_an_int_where_the_sum_fits(): void
    {
        foreach ([
            [4611686018427387904, 4611686018427387903, PHP_INT_MAX, '4611686018427387903.5000'],
            [-4611686018427387904, -4611686018427387904, PHP_INT_MIN, '-4611686018427387904.0000'],
            [PHP_INT_MAX, 0, PHP_INT_MAX, '4611686018427387903.5000'],
            [PHP_INT_MIN, 0, PHP_INT_MIN, '-4611686018427387904.0000'],
            [PHP_INT_MAX, -1, PHP_INT_MAX - 1, '4611686018427387903.0000'],
            [PHP_INT_MIN, 1, PHP_INT_MIN + 1, '-4611686018427387903.5000'],
            [PHP_INT_MAX, PHP_INT_MIN, -1, '-0.5000'],
        ] as [$first, $second, $sum, $average]) {
            $this->set_big($first, $second);
            $case = "$first + $second";

            $this->assert_same($sum, Measurement::sum('big'), "sum $case");
            $this->assert_same($average, Measurement::avg('big'), "avg $case");
            $this->assert_same(max($first, $second), Measurement::max('big'), "max $case");
            $this->assert_same(min($first, $second), Measurement::min('big'), "min $case");
        }
    }

    public function test_the_aggregate_of_one_bigint_is_an_int(): void
    {
        $this->assert_same(4611686018427387904, Measurement::where('label', 'a')->sum('big'));
        $this->assert_same(4611686018427387904, Measurement::where('label', 'a')->max('big'));
        $this->assert_same(4611686018427387904, Measurement::where('label', 'a')->min('big'));
        $this->assert_same('4611686018427387904.0000', Measurement::where('label', 'a')->avg('big'));
        $this->assert_same(4611686018427387904, Measurement::max('big'));
    }

    // A whole average still has its decimals, and half way rounds away from zero (ExactDecimal; the tie of 1/32 is 0.03125)
    public function test_an_integer_average_rounds_half_up_at_four_decimals(): void
    {
        $this->third_row();

        $this->assert_same('1.5000', Measurement::where_in('label', ['a', 'b'])->avg('qty'));
        $this->assert_same('2.0000', Measurement::where_in('label', ['b', 'c'])->avg('qty'));

        // 32 values summing to 1 (0.03125), and to -1 (-0.03125)
        $this->conn->query('DELETE FROM measurements');

        foreach (range(1, 32) as $i) {
            $values = [1 === $i ? 1 : 0, 'tie'];
            $this->conn->query('INSERT INTO measurements (qty, label) VALUES (?, ?)', $values);
        }

        $this->assert_same('0.0313', Measurement::avg('qty'));

        $this->conn->query("UPDATE measurements SET qty = -qty WHERE label = 'tie'");
        $this->assert_same('-0.0313', Measurement::avg('qty'));
    }
}
