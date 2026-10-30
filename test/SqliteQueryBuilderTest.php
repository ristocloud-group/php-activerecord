<?php

use ActiveRecord\Column;

/**
 * The query builder on SQLite: what its adapter writes its own way, and the DATE binds it fixes there.
 *
 * Fixture data: authors 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob; measurements 1 (qty 1, big 2^62, amount
 * 1234567890123.45, ratio 0.1) and 2 (qty 2, big 2^62, amount 0.01, ratio 0.2); books 1 and 2, whose NUMERIC special is 0;
 * dated_counts.
 */
class SqliteQueryBuilderTest extends DatabaseTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('sqlite');
    }

    // Spec §5.2: SQLite's LIKE ignores the case of ASCII letters; GLOB does not, and has wildcards of its own
    public function test_case_sensitive_like_is_glob_with_translated_pattern(): void
    {
        $q = Author::where_like('name', 'T_t%', true);
        $this->assert_sql_has('name GLOB ?', $q->to_sql());
        $this->assert_same(['T?t*'], $q->get_bindings());
    }

    // SQLite reads a statement terminator as it reads SQL (QueryCompiler::refuse_statement_terminator()): no backslash
    // escape in a string, identifiers in double quotes, backticks or brackets, -- and block comments that do not nest, and
    // no dollar quote: #, $, @ and : start a parameter (#a, $$a, @a$$), left unbound (NULL). Each ; below that it reads
    // outside is refused at the call, and its limited delete sends nothing, where it deleted all four authors ('C:\' ;,
    // IS NOT #a;, IS NOT $$a;b$$, @a$$ ... @b$$); the fragments accepted here are sent, and their delete writes one row
    public function test_a_statement_terminator_is_read_as_sqlite_reads_it(): void
    {
        foreach (["name != 'it''s;'", 'author_id > 0 AND `a;b` IS NULL', 'author_id > 0 AND [a;b] IS NULL', 'author_id > 0 -- ;'] as $sql) {
            $this->assert_sql_has($sql, Author::where_raw($sql)->to_sql());
        }

        $this->assert_same(1, Author::where_raw("name != 'it''s;' AND author_id > 0 /* ; */")->order_by('author_id')->limit(1)->delete());
        Author::table();

        foreach ([
            "name != 'C:\\' ; '",
            'author_id IS NOT #a;',
            'author_id IS NOT $$a;b$$',
            'author_id > 0 OR @a$$ IS NULL; @b$$',
            'author_id > 0 # ;',
            "name != 'a\\';b'",
            'author_id > 0 /* /* */ ; */',
        ] as $sql) {
            $this->assert_same(
                [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => "SQL '$sql' in where_raw() on Author holds a statement terminator (;) outside its strings and comments: MySQL, MariaDB and SQLite would end the statement there and drop the clauses after it, and Postgres would refuse it. Bind a value with a ? marker"]],
                GoldenStatements::capture(fn() => Author::where_raw($sql)->order_by('author_id')->limit(1)->delete()),
                $sql
            );
        }

        $this->assert_same(3, (int) Author::count());
    }

    // SQLite reads a parameter (#, $, @ or : and a name, :: in it) with a Tcl-style suffix as one token, up to the first )
    // or whitespace: a quote, a bracket or a ; in its parentheses is the parameter's. So the ; after $a(') or @a([) is
    // outside, and refused, by the builder and by the legacy conditions alike, where a reading that opened a string or a
    // bracket in the parentheses let it through and the limited delete deleted all four authors; and $a(;) holds no
    // terminator: the parameter, bound to nothing, is NULL, and the LIMIT after it applies
    public function test_a_tcl_style_parameter_is_read_as_one_token(): void
    {
        Author::table();
        $refusal = fn(string $sql, string $source): array => [[
            'exception' => ActiveRecord\ActiveRecordException::class,
            'message' => "SQL '$sql' in $source on Author holds a statement terminator (;) outside its strings and comments: MySQL, MariaDB and SQLite would end the statement there and drop the clauses after it, and Postgres would refuse it. Bind a value with a ? marker",
        ]];

        foreach (["author_id IS NOT \$a(') ; '", 'author_id IS NOT @a([) ; ]', "author_id IS NOT :a::b(') ; '", "author_id IS NOT #a(') ; '"] as $sql) {
            $this->assert_same($refusal($sql, 'where_raw()'), GoldenStatements::capture(fn() => Author::where_raw($sql)->order_by('author_id')->limit(1)->delete()), $sql);
            $this->assert_same($refusal($sql, "the 'conditions' option"), GoldenStatements::capture(fn() => Author::delete_all(['conditions' => $sql, 'order' => 'author_id', 'limit' => 1])), "delete_all $sql");
        }

        $this->assert_same(4, (int) Author::count());
        $this->assert_same(1, Author::where_raw('author_id IS NOT $a(;)')->order_by('author_id')->limit(1)->delete());
        $this->assert_same(3, (int) Author::count());
    }

    // Spec §5.2: a DATE column holds '2026-01-02', which a positional DateTime, sent as a date and time, never
    // equals as text; the builder knows the column, and sends a date
    public function test_legacy_positional_date_differs_from_the_builder(): void
    {
        $this->assert_same(0, DatedCount::count(['conditions' => ['day = ?', new DateTime('2026-01-02')]])); // documented (§5.2)
        $this->assert_same(1, count(DatedCount::where('day', new DateTime('2026-01-02'))->get()));
    }

    // GLOB's own wildcards and brackets stand for themselves in a bracket, then LIKE's wildcards become GLOB's
    public function test_glob_patterns_and_the_rows_found(): void
    {
        $this->assert_same('a[[]b][*]c[?]*?', $this->conn->like_pattern('a[b]*c?%_', true));
        $this->assert_same('a[b]*c?%_', $this->conn->like_pattern('a[b]*c?%_', false));

        $this->assert_same('SELECT * FROM `authors` WHERE `name` LIKE ?', Author::where_like('name', 'T_t%')->to_sql());
        $this->assert_same('SELECT * FROM `authors` WHERE `name` NOT GLOB ?', Author::where_not_like('name', 'T%', true)->to_sql());
        $this->assert_same(['T_t%'], Author::where_like('name', 'T_t%')->get_bindings());

        $this->assert_same(1, count(Author::where_like('name', 'T_t%', true)->get()));
        $this->assert_same(0, count(Author::where_like('name', 't_t%', true)->get()));
        $this->assert_same(1, count(Author::where_like('name', 't_t%')->get()));
        $this->assert_same(4, count(Author::where_not_like('name', 't%', true)->get()));
    }

    // Spec §5.2: Laravel's SQLite grammar adds no operator to the base ones, so neither does the adapter
    public function test_no_operator_of_its_own(): void
    {
        $this->assert_same([], $this->conn->extra_operators());

        foreach (['sounds like', 'is distinct from', '@>', 'glob'] as $operator) {
            $this->assert_exception_message_contains("Unknown operator '$operator' in where() on Author", fn() => Author::where('name', $operator, 'x'), ActiveRecord\ActiveRecordException::class);
        }
    }

    // Spec §5.2: in_random_order() is RANDOM() on SQLite, through the adapter's random_function()
    public function test_in_random_order_is_random(): void
    {
        $this->assert_same('RANDOM()', $this->conn->random_function());
        $this->assert_same('SELECT * FROM `authors` ORDER BY RANDOM()', Author::in_random_order()->to_sql());
        $this->assert_same('SELECT * FROM `authors` ORDER BY `name` ASC, RANDOM()', Author::order_by('name')->in_random_order()->to_sql());

        $ids = array_map(fn(Author $author): int => $author->author_id, Author::in_random_order()->get());
        sort($ids);
        $this->assert_same([1, 2, 3, 4], $ids);
    }

    // SQLite reads an alias of the select in a HAVING, so count() and exists() keep the select of a query that has a
    // HAVING (spec §5.3). Parent 2 is the one of more than one author.
    public function test_a_having_on_an_alias_of_the_select_is_counted_and_exists(): void
    {
        $parents = fn(int $authors): ActiveRecord\Query => Author::select('parent_author_id')->select_raw('COUNT(*) AS n')->group_by('parent_author_id')->having('n', '>', $authors);

        $this->assert_same(1, count($parents(1)->get()));
        $this->assert_same(1, $parents(1)->count());
        $this->assert_same(0, $parents(2)->count());
        $this->assert_true($parents(1)->exists());
        $this->assert_false($parents(2)->exists());
        $this->assert_true($parents(2)->doesnt_exist());

        // a HAVING without a GROUP BY: the aggregate makes one row of the rows, and the HAVING names its alias
        $everyone = fn(int $authors): ActiveRecord\Query => Author::select_raw('COUNT(*) AS n')->having('n', '>', $authors);
        $this->assert_same(1, count($everyone(3)->get()));
        $this->assert_same(1, $everyone(3)->count());
        $this->assert_true($everyone(3)->exists());
        $this->assert_false($everyone(4)->exists());
    }

    // to_raw_sql() writes each value as the adapter binds it (spec §4.2). SQLite binds an int, and a bool that turns into
    // one, as an INTEGER, and every other value as text, so the text of a statement finds what the bound one finds where
    // a value meets an expression and no column: quoted, the integer 1 never equals the text '1', and a number is less
    // than any text, so `c > '1'` finds nothing. Parent 2 is the one of two authors, parents 1 and 3 of one each, and
    // 'George W. Bush' is the name of 14 characters.
    public function test_the_raw_sql_of_a_comparison_that_names_no_column_finds_the_bound_rows(): void
    {
        $groups = fn(int $more_than): ActiveRecord\Query => Author::select_raw('COUNT(*) AS c')->group_by('parent_author_id')->having('c', '>', $more_than);

        foreach ([1 => 1, 0 => 3, 2 => 0] as $more_than => $found) {
            $this->assert_same($found, count($groups($more_than)->get()), "bound, c > $more_than");
            $this->assert_same($found, count($this->conn->query($groups($more_than)->to_raw_sql())->fetchAll()), "raw, c > $more_than");
        }

        $this->assert_same('SELECT COUNT(*) AS c FROM `authors` GROUP BY `parent_author_id` HAVING `c` > 1', $groups(1)->to_raw_sql());

        $length = Author::where_raw('length(name) = ?', [14]);
        $this->assert_same(['George W. Bush'], array_map(fn(Author $author): string => $author->name, $length->get()));
        $this->assert_same('SELECT * FROM `authors` WHERE length(name) = 14', $length->to_raw_sql());
        $this->assert_same(1, count($this->conn->query($length->to_raw_sql())->fetchAll()));

        // a literal against a literal, true and 1 alike: both are bound as the integer 1
        foreach ([1, true] as $one) {
            $query = Author::where_raw('? = 1', [$one]);
            $this->assert_same(4, count($query->get()));
            $this->assert_same('SELECT * FROM `authors` WHERE 1 = 1', $query->to_raw_sql());
            $this->assert_same(4, count($this->conn->query($query->to_raw_sql())->fetchAll()));
        }

        // a float, and a numeric string, are bound as text and written as text, so they do not equal an integer in either
        $this->assert_same(0, count(Author::where_raw('? = 1', [1.0])->get()));
        $this->assert_same("SELECT * FROM `authors` WHERE '1' = 1", Author::where_raw('? = 1', [1.0])->to_raw_sql());
        $this->assert_same(0, count($this->conn->query(Author::where_raw('? = 1', ['1'])->to_raw_sql())->fetchAll()));
    }

    // The text and the bound value have one storage class, whatever the value: the adapter binds an int, and a bool that
    // turns into one, as an integer (written bare), null as NULL, and everything else as text, a float and a numeric
    // string included (written quoted). The hook that tells them is the adapter's, and agrees with how it binds.
    public function test_the_raw_literal_has_the_storage_class_of_the_bound_value(): void
    {
        $text = new class {
            public function __toString(): string
            {
                return '9';
            }
        };
        $values = [0, 1, -7, PHP_INT_MAX, PHP_INT_MIN, true, false, 0.0, 1.0, 2.5, -0.5, 1.0e25, '0', '1', '', ' 5', 'x', "it's", null, $text];

        foreach ($values as $i => $value) {
            $expected = match (true) {
                is_int($value) || is_bool($value) => 'integer',
                null === $value => 'null',
                default => 'text',
            };
            $query = Author::select_raw('typeof(?) AS t', [$value])->limit(1);

            $this->assert_same($expected, $query->get()[0]->t, "bound, value $i");
            $this->assert_same($expected, $this->conn->query($query->to_raw_sql())->fetch(PDO::FETCH_NUM)[0], "raw, value $i: " . $query->to_raw_sql());
            $this->assert_same('integer' === $expected, $this->conn->binds_as_integer($value), "binds_as_integer(), value $i");
        }
    }

    // Spec §5.4, §10: the raw types this adapter reports for the columns of the measurements, which classify their
    // aggregates. SQLite gives the first word of the declared type, lower-cased: 'decimal' for DECIMAL(20,2), 'double'
    // for DOUBLE (and DOUBLE PRECISION), 'int' for INT and INTEGER. A column declared REAL (single) is (STRING, 'real'): the
    // type mapping has no such key, so the raw type alone makes it floating.
    public function test_the_raw_types_of_the_measurements(): void
    {
        $this->assert_same(
            ['id' => [Column::INTEGER, 'int'], 'qty' => [Column::INTEGER, 'int'], 'big' => [Column::INTEGER, 'bigint'], 'amount' => [Column::DECIMAL, 'decimal'], 'ratio' => [Column::DECIMAL, 'double'], 'single' => [Column::STRING, 'real'], 'label' => [Column::STRING, 'varchar']],
            array_map(fn(Column $column): array => [$column->type, $column->raw_type], Measurement::table()->columns)
        );
    }

    // Spec §5.4: SQLite stores DECIMAL as a REAL (NUMERIC affinity), so the aggregates of the decimal column are floats, the
    // floating path; and a SUM of integers beyond 2^63 is SQLite's own "integer overflow" error, which a query surfaces as a
    // DatabaseException: SQLite has no wider integer to sum into. Both are documented limits of this adapter.
    public function test_sqlite_decimal_is_floating_and_big_sum_overflows(): void
    {
        $this->assert_true(is_float(Measurement::sum('amount')));
        $this->assert_exception_message_contains('overflow', fn() => Measurement::sum('big'), ActiveRecord\DatabaseException::class);
    }

    // The documented limit, on each statement that sums the column: the SUM, and the AVG, which is a SUM and a COUNT
    public function test_a_bigint_sum_beyond_the_int_range_overflows_in_sum_and_avg(): void
    {
        $this->assert_exception_message_contains('integer overflow', fn() => Measurement::sum('big'), ActiveRecord\DatabaseException::class);
        $this->assert_exception_message_contains('integer overflow', fn() => Measurement::avg('big'), ActiveRecord\DatabaseException::class);
        $this->assert_exception_message_contains('integer overflow', fn() => BigId::sum('id'), ActiveRecord\DatabaseException::class);

        // the MIN and the MAX do not sum, and a sum that fits is exact: one BIGINT of 2^62
        $this->assert_same(4611686018427387904, Measurement::max('big'));
        $this->assert_same(4611686018427387904, Measurement::min('big'));
        $this->assert_same(PHP_INT_MAX, BigId::max('id'));
        $this->assert_same(4611686018427387904, Measurement::where('label', 'a')->sum('big'));
    }

    // DECIMAL is REAL storage here: the aggregates of the column are floats, which the driver gives, never the exact
    // strings of the other databases. The expected values are the doubles of the decimal digits.
    public function test_decimal_aggregates_are_floats(): void
    {
        $this->assert_true(abs(Measurement::sum('amount') - 1234567890123.46) < 1e-6);
        $this->assert_true(abs(Measurement::avg('amount') - 617283945061.73) < 1e-6);
        $this->assert_same(0.01, Measurement::min('amount'));
        $this->assert_same(1234567890123.45, Measurement::max('amount'));
        $this->assert_true(is_float(Measurement::average('amount')));
        $this->assert_same(0.0, Measurement::where('label', 'zzz')->sum('amount'));
        $this->assert_null(Measurement::where('label', 'zzz')->avg('amount'));
        $this->assert_null(Measurement::where('label', 'zzz')->max('amount'));
        $this->assert_true(is_float($this->strict_call(fn() => Measurement::sum('amount'))));
        // a windowed query too
        $this->assert_same(1234567890123.45, Measurement::order_by('id')->limit(1)->sum('amount'));
        $this->assert_same(1234567890123.45, Measurement::order_by('id')->limit(1)->avg('amount'));
    }

    // A whole decimal is stored as an INTEGER (NUMERIC affinity), and SQLite sums integers into an integer: the type of the
    // aggregate does not depend on the values, it is a float for the column, as the floating path says
    public function test_a_whole_decimal_is_a_float_too(): void
    {
        $this->assert_same('integer', $this->conn->query('SELECT typeof(special) FROM books WHERE book_id = 1')->fetch(PDO::FETCH_NUM)[0]);
        $this->assert_same('real', $this->conn->query('SELECT typeof(amount) FROM measurements WHERE id = 1')->fetch(PDO::FETCH_NUM)[0]);

        $this->assert_same(0.0, $this->strict_call(fn() => Book::sum('special')));
        $this->assert_same(0.0, $this->strict_call(fn() => Book::min('special')));
        $this->assert_same(0.0, $this->strict_call(fn() => Book::max('special')));
        $this->assert_same(0.0, $this->strict_call(fn() => Book::avg('special')));
        $this->assert_same(0.0, $this->strict_call(fn() => Book::where('book_id', 99)->sum('special')));
    }

    // SQLite types the value, not the column: an INTEGER column holds a REAL when the value has a fraction, and its SUM, its
    // MIN and its AVG are then floats. The result is that float, unchanged: it never goes to ExactDecimal, whose int|string
    // parameters PHP would hand the int 1 for 1.5, with a deprecation. A MAX that is a whole number is an integer.
    public function test_a_real_in_an_integer_column_is_returned_as_the_float_it_is(): void
    {
        $this->conn->query('UPDATE measurements SET qty = 1.5 WHERE id = 1');
        $this->assert_same('real', $this->conn->query('SELECT typeof(qty) FROM measurements WHERE id = 1')->fetch(PDO::FETCH_NUM)[0]);

        $this->assert_same(3.5, $this->strict_call(fn() => Measurement::sum('qty')));
        $this->assert_same(1.5, $this->strict_call(fn() => Measurement::min('qty')));
        $this->assert_same(2, $this->strict_call(fn() => Measurement::max('qty')));
        $this->assert_same(1.75, $this->strict_call(fn() => Measurement::avg('qty')));
        $this->assert_same(1.75, $this->strict_call(fn() => Measurement::average('qty')));
        $this->assert_same(1.5, $this->strict_call(fn() => Measurement::where('label', 'a')->sum('qty')));
        $this->assert_same(1.5, $this->strict_call(fn() => Measurement::where('label', 'a')->avg('qty')));
    }

    // A column that is not the model's has no type to read by, so its aggregates are the driver's values, with SQLite's types: a
    // SUM of integers is an int, an AVG is a float, a MIN and a MAX are ints
    public function test_a_column_that_is_not_the_models_is_the_value_sqlite_returns(): void
    {
        $this->assert_same(3, Book::joins('author')->sum('authors.author_id'));
        $this->assert_same(3, Book::joins('author')->sum('author_id'));
        $this->assert_same(1.5, Book::joins('author')->avg('authors.author_id'));
        $this->assert_same(1, Book::joins('author')->min('authors.author_id'));
        $this->assert_same(2, Book::joins('author')->max('authors.author_id'));
    }

    // An expression that the select names has no model column either: SQLite's SUM of integers is an int, its AVG a float, its MIN
    // and MAX ints. qty is 1 and 2, so doubled is 2 and 4.
    public function test_an_expression_alias_gives_the_values_sqlite_returns(): void
    {
        $doubled = fn(): ActiveRecord\Query => Measurement::select_raw('qty * 2 AS doubled')->order_by('id')->limit(10);

        $this->assert_same(6, $doubled()->sum('doubled'));
        $this->assert_same(3.0, $doubled()->avg('doubled'));
        $this->assert_same(3.0, $doubled()->average('doubled'));
        $this->assert_same(2, $doubled()->min('doubled'));
        $this->assert_same(4, $doubled()->max('doubled'));
        // no row: NULL, where a column of the model has a 0 of its type
        $this->assert_null($doubled()->where('label', 'zzz')->sum('doubled'));
        $this->assert_null($doubled()->where('label', 'zzz')->avg('doubled'));
    }

    // SQLite's SUM of integers is an int, and so the AVG of an integer column is exact here too, as everywhere
    public function test_the_average_of_an_integer_column_is_exact_here_too(): void
    {
        Measurement::create(['qty' => 2, 'label' => 'c']);

        $this->assert_same('1.6667', Measurement::avg('qty'));
        $this->assert_same(5, Measurement::sum('qty'));
        $this->assert_same('4611686018427387904.0000', Measurement::where('label', 'a')->avg('big'));
    }

    // A boolean column aggregates as an integer: sum() counts the trues, avg() is exact (SQLite sums integers into an integer,
    // so its own AVG is not asked), min() and max() are the ints 0 and 1. The six venues are all available, none is retired.
    public function test_a_boolean_column_aggregates_as_an_integer(): void
    {
        $this->assert_same(6, Venue::sum('is_available'));
        $this->assert_same('1.0000', Venue::avg('is_available'));
        $this->assert_same('1.0000', Venue::average('is_available'));
        $this->assert_same(1, Venue::min('is_available'));
        $this->assert_same(1, Venue::max('is_available'));
        $this->assert_same(0, Venue::sum('is_retired'));
        $this->assert_same('0.0000', Venue::avg('is_retired'));
        $this->assert_same(0, Venue::min('is_retired'));
        $this->assert_same(0, Venue::max('is_retired'));

        // two of the six not available
        $this->conn->query("UPDATE venues SET is_available = 0 WHERE state = 'DC'");
        $this->assert_same(4, Venue::sum('is_available'));
        $this->assert_same('0.6667', Venue::avg('is_available'));
        $this->assert_same(0, Venue::min('is_available'));
        $this->assert_same(1, Venue::max('is_available'));

        // no row
        $this->assert_same(0, Venue::where('name', 'zzz')->sum('is_available'));
        $this->assert_null(Venue::where('name', 'zzz')->avg('is_available'));
        $this->assert_null(Venue::where('name', 'zzz')->max('is_available'));
    }

    // SQLite needs a LIMIT before an OFFSET: an offset alone goes with an unbounded one, LIMIT -1 (#130)
    public function test_the_window_is_limit_with_an_offset_before_the_count(): void
    {
        $this->assert_same('SELECT * FROM `authors` LIMIT 2', Author::limit(2)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 1,2', Author::limit(2)->offset(1)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT -1 OFFSET 1', Author::offset(1)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 2,2', Author::for_page(2, 2)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 0', Author::limit(0)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 9223372036854775806,1', Author::for_page(PHP_INT_MAX, 1)->to_sql());
    }
}
