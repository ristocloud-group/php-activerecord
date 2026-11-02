<?php

use ActiveRecord\Column;

/**
 * The query builder on Postgres: what its adapter writes its own way.
 *
 * Fixture data: authors 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob; measurements 1 (qty 1, big 2^62, amount
 * 1234567890123.45, ratio 0.1) and 2 (qty 2, big 2^62, amount 0.01, ratio 0.2); big_ids 1 and PHP_INT_MAX.
 */
class PgsqlQueryBuilderTest extends DatabaseTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('pgsql');
    }

    // Spec §5.2: Postgres's LIKE is case-sensitive, and ILIKE is not; the column is compared as text, as
    // Laravel's grammar compares it
    public function test_like_is_ilike_unless_case_sensitive(): void
    {
        $this->assert_sql_has('name::text ILIKE ?', Author::where_like('name', 't%')->to_sql());
        $this->assert_sql_has('name::text LIKE ?', Author::where_like('name', 'T%', true)->to_sql());
    }

    public function test_not_like_and_the_rows_found(): void
    {
        $this->assert_same('SELECT * FROM "authors" WHERE "name"::text NOT ILIKE ?', Author::where_not_like('name', 't%')->to_sql());
        $this->assert_same('SELECT * FROM "authors" WHERE "name"::text NOT LIKE ?', Author::where_not_like('name', 'T%', true)->to_sql());
        $this->assert_same(['T%'], Author::where_like('name', 'T%', true)->get_bindings());

        $this->assert_same(1, count(Author::where_like('name', 't%')->get()));
        $this->assert_same(0, count(Author::where_like('name', 't%', true)->get()));
        $this->assert_same(3, count(Author::where_not_like('name', 't%')->get()));
    }

    // Laravel's grammar casts the column to text in where()'s LIKE operators too: those of the LIKE family, and no
    // other operator, and not where_column()
    public function test_the_like_operators_of_where_compare_the_column_as_text(): void
    {
        foreach (['like', 'not like', 'ilike', 'not ilike'] as $operator) {
            $this->assert_same('SELECT * FROM "authors" WHERE "name"::text ' . strtoupper($operator) . ' ?', Author::where('name', $operator, 'x')->to_sql());
        }

        $this->assert_same('SELECT * FROM "authors" WHERE ("name"::text ILIKE ? OR "some_text"::text ILIKE ?)', Author::where_any(['name', 'some_text'], 'ilike', 'x')->to_sql());
        $this->assert_same('SELECT * FROM "authors" WHERE "name" = ?', Author::where('name', 'x')->to_sql());
        $this->assert_same('SELECT * FROM "authors" WHERE "name" LIKE "some_text"', Author::where_column('name', 'like', 'some_text')->to_sql());

        $this->assert_same(['Tito'], array_map(fn(Author $author): string => $author->name, Author::where('name', 'ilike', 't%')->get()));
        $this->assert_same(['Tito'], array_map(fn(Author $author): string => $author->name, Author::where('author_id', 'like', '1%')->get()));
    }

    // Spec §5.2: the operators Laravel's Postgres grammar adds that compare, in any case and written in upper case.
    // Refused at the call: those with a ?, which would read as bind markers; those that cannot be the boolean of
    // column OP ? (BETWEEN needs AND, ||, -, # and #- compute a value); MySQL's.
    public function test_the_adapters_own_operators(): void
    {
        $this->assert_same(['<<=', '>>=', '&&', '@>', '<@', '@@', 'is distinct from', 'is not distinct from'], $this->conn->extra_operators());
        $this->assert_same('SELECT * FROM "authors" WHERE "name" IS DISTINCT FROM ?', Author::where('name', 'Is Distinct From', 'Tito')->to_sql());
        $this->assert_same('SELECT * FROM "authors" WHERE "name" @> ?', Author::where('name', '@>', 'x')->to_sql());

        $names = fn(ActiveRecord\Query $query): array => array_map(fn(Author $author): string => $author->name, $query->get());
        $this->assert_same(['Tito'], $names(Author::where('name', 'is not distinct from', 'Tito')));
        $this->assert_same(3, count(Author::where('name', 'is distinct from', 'Tito')->get()));
        // text search: the words of the name match those of the value
        $this->assert_same(['Uncle Bob'], $names(Author::where('name', '@@', 'bob')));

        foreach (['sounds like', 'between', '||', '-', '#', '#-', '?', '?|', '?&', '@?'] as $operator) {
            $this->assert_exception_message_contains("Unknown operator '$operator' in where() on Author", fn() => Author::where('name', $operator, 'x'), ActiveRecord\ActiveRecordException::class);
        }
    }

    // Spec §5.2: in_random_order() is RANDOM() on Postgres, through the adapter's random_function()
    public function test_in_random_order_is_random(): void
    {
        $this->assert_same('RANDOM()', $this->conn->random_function());
        $this->assert_same('SELECT * FROM "authors" ORDER BY RANDOM()', Author::in_random_order()->to_sql());
        $this->assert_same('SELECT * FROM "authors" ORDER BY "name" ASC, RANDOM()', Author::order_by('name')->in_random_order()->to_sql());
        $this->assert_same([1, 2, 3, 4], array_map(fn(Author $author): int => $author->author_id, Author::order_by('author_id')->get()));

        $ids = array_map(fn(Author $author): int => $author->author_id, Author::in_random_order()->get());
        sort($ids);
        $this->assert_same([1, 2, 3, 4], $ids);
    }

    // The conditions of a HAVING are those of a WHERE (spec §3.2): the LIKE operators compare the column as text,
    // and no other operator does
    public function test_the_like_operators_of_having_compare_the_column_as_text(): void
    {
        foreach (['like', 'not like', 'ilike', 'not ilike'] as $operator) {
            $this->assert_same('SELECT * FROM "authors" GROUP BY "name" HAVING "name"::text ' . strtoupper($operator) . ' ?', Author::group_by('name')->having('name', $operator, 'x')->to_sql());
        }

        $this->assert_same('SELECT * FROM "authors" GROUP BY "name" HAVING "name" = ? OR "name"::text ILIKE ?', Author::group_by('name')->having('name', 'x')->or_having('name', 'ilike', 'x')->to_sql());
        $this->assert_same(['Tito'], array_map(fn(Author $author): string => $author->name, Author::select('name')->group_by('name')->having('name', 'ilike', 't%')->get()));
    }

    // A ; in a dollar-quoted string ($$...$$, $tag$...$tag$) or in an identifier in double quotes is no statement
    // terminator: the SQL is sent, and Postgres reads the string and the identifier (a ; outside them is refused at the call,
    // QueryWhereTest). A $1 opens no dollar quote, and a ; after it is refused
    public function test_a_semicolon_in_a_dollar_quoted_string_is_sent(): void
    {
        $names = fn(array $authors): array => array_map(fn(Author $author): string => $author->name, $authors);
        Author::new_query()->insert(['name' => 'a;b']);

        $this->assert_same(['a;b'], $names(Author::where_raw('name = $$a;b$$')->get()));
        $this->assert_same(['a;b'], $names(Author::where_raw('name IN($tag$a;b$tag$, $$;$$)')->get()));
        $this->assert_same(1, Author::where_raw('name != $$a;b$$')->order_by('author_id')->limit(1)->delete());
        $this->assert_same(['George W. Bush'], array_map(fn(Author $author): string => $author->{'a;b'}, Author::select_raw('name AS "a;b"')->order_by('author_id')->limit(1)->get()));
        $this->assert_exception_message_contains("SQL 'name = $1;' in where_raw() on Author holds a statement terminator (;)", fn() => Author::where_raw('name = $1;'), ActiveRecord\ActiveRecordException::class);
    }

    // Postgres reads a statement terminator as it reads SQL (QueryCompiler::refuse_statement_terminator()): a standard
    // string has no backslash escape, an E'' string has them (and a doubled quote), an identifier is in double quotes, a
    // dollar quote opens at a $ that follows no character of an identifier (a$$ is an identifier), -- always opens a
    // comment, and a block comment nests. # is an operator and a backtick no quote: a ; after them is refused, as is one
    // after 'C:\', where the string ends. The fragments accepted here are sent, and their limited delete writes one row
    public function test_a_statement_terminator_is_read_as_postgres_reads_it(): void
    {
        foreach (["name != e'it''s;' AND name != E'a\\';b'", 'author_id > 0 /* /* */ ; */', 'author_id > 0--1;'] as $sql) {
            $this->assert_same(1, Author::where_raw($sql)->order_by('author_id')->limit(1)->delete(), $sql);
        }

        Author::table();

        foreach ([
            "name != 'C:\\' ; '",
            'author_id > 0 # ;',
            'author_id > 0 AND `a;b` IS NULL',
            'author_id > 0 OR @a$$ IS NULL; @b$$',
            'author_id > 0 OR a$$ IS NULL; $$',
        ] as $sql) {
            $this->assert_same(
                [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => "SQL '$sql' in where_raw() on Author holds a statement terminator (;) outside its strings and comments: MySQL, MariaDB and SQLite would end the statement there and drop the clauses after it, and Postgres would refuse it. Bind a value with a ? marker"]],
                GoldenStatements::capture(fn() => Author::where_raw($sql)->order_by('author_id')->limit(1)->delete()),
                $sql
            );
        }

        $this->assert_same(1, (int) Author::count());
    }

    // A block comment left open is read as Postgres reads it (QueryCompiler::refuse_statement_terminator()): it nests, so
    // /* /* */ is still open, and /* /* */ */ closed. A dollar-quoted string holds a /*, and # is an operator, after which
    // a /* opens a comment. The fragments accepted here are sent, and their limited delete writes one row; an open one is
    // refused at the call, by the builder and by the legacy conditions alike, where Postgres refused the statement
    public function test_a_block_comment_left_open_is_read_as_postgres_reads_it(): void
    {
        foreach (['author_id > 0 /* /* */ */', 'name != $$/*$$', "name != E'a\\'/*'"] as $sql) {
            $this->assert_same(1, Author::where_raw($sql)->order_by('author_id')->limit(1)->delete(), $sql);
        }

        Author::table();

        foreach (['author_id > 0 /* /* */', "name != 'C:\\' /* '", 'author_id > 0 # /*'] as $sql) {
            $message = "leaves a block comment open (/* with no */ after it): SQLite would run the comment to the end of the statement and drop the clauses after it, and MySQL, MariaDB and Postgres would refuse it. Close the comment with */";
            $this->assert_same(
                [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => "SQL '$sql' in where_raw() on Author $message"]],
                GoldenStatements::capture(fn() => Author::where_raw($sql)->order_by('author_id')->limit(1)->delete()),
                $sql
            );
            $this->assert_same(
                [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => "SQL '$sql' in the 'conditions' option on Author $message"]],
                GoldenStatements::capture(fn() => Author::delete_all(['conditions' => $sql, 'order' => 'author_id', 'limit' => 1])),
                "delete_all $sql"
            );
        }

        $this->assert_same(1, (int) Author::count());
    }

    // Spec §5.3: a value that an INTEGER pk can never equal fails the statement on Postgres (22P02) and aborts the
    // transaction, so a find looks it up in no statement, as the legacy finders by pk do (#92). The table is loaded
    // first: a table read cold adds its schema read to the capture.
    public function test_find_non_integer_pk_never_queries(): void
    {
        Author::table();
        $statements = GoldenStatements::capture(fn() => $this->assert_null(Author::new_query()->find('abc')));
        $this->assert_same([], $statements);
    }

    // to_raw_sql() writes each value as the adapter binds it (spec §4.2): as quoted text, which Postgres takes for a
    // boolean and for a text column as it takes the text that is bound, where it refuses the bare literals
    public function test_the_raw_sql_writes_every_value_as_quoted_text_which_postgres_takes_for_any_type(): void
    {
        foreach ([5, -7, true, false, 2.5, '5', null] as $value) {
            $this->assert_false($this->conn->binds_as_integer($value));
        }

        // a bool against a boolean column, a number against a text column, a number against an integer one
        foreach ([Venue::where('is_available', true), Venue::where('is_available', false), Venue::where('is_retired', false), Author::where('name', 5), Author::where('author_id', 2)] as $i => $query) {
            $this->assert_same(count($query->get()), count($this->conn->query($query->to_raw_sql())->fetchAll()), "the query $i: " . $query->to_raw_sql());
        }

        $this->assert_same('SELECT * FROM "venues" WHERE "is_available" = \'1\'', Venue::where('is_available', true)->to_raw_sql());
        $this->assert_same('SELECT * FROM "authors" WHERE "name" = \'5\'', Author::where('name', 5)->to_raw_sql());

        // what the bare literals would have done
        $this->assert_exception_message_contains('operator does not exist', fn() => $this->conn->query('SELECT * FROM venues WHERE is_available = 1'), ActiveRecord\DatabaseException::class);
        $this->assert_exception_message_contains('operator does not exist', fn() => $this->conn->query('SELECT * FROM authors WHERE name = 5'), ActiveRecord\DatabaseException::class);
    }

    // Spec §5.4, §10: the raw types this adapter reports for the columns of the measurements, which classify their
    // aggregates. Postgres gives the first word of format_type(): 'numeric' for decimal too, 'double' for double
    // precision and float8, and 'int' for integer. The real column (single, real or float4) is (STRING, 'real'): the type
    // mapping has no such key, so the raw type alone makes a column floating.
    public function test_the_raw_types_of_the_measurements(): void
    {
        $this->assert_same(
            ['id' => [Column::INTEGER, 'int'], 'qty' => [Column::INTEGER, 'int'], 'big' => [Column::INTEGER, 'bigint'], 'amount' => [Column::DECIMAL, 'numeric'], 'ratio' => [Column::DECIMAL, 'double'], 'single' => [Column::STRING, 'real'], 'label' => [Column::STRING, 'varchar']],
            array_map(fn(Column $column): array => [$column->type, $column->raw_type], Measurement::table()->columns)
        );
    }

    // The aggregates of a numeric column are the exact strings Postgres returns, and a SUM of bigints, which Postgres
    // sums into a numeric, is its exact digits beyond PHP_INT_MAX. The AVG is exact at the scale of the SUM plus four,
    // not Postgres's own (a numeric average has 16 or more digits).
    public function test_exact_decimal_and_big_sums(): void
    {
        $this->assert_same('1234567890123.46', Measurement::sum('amount'));
        $this->assert_same('617283945061.730000', Measurement::avg('amount'));
        $this->assert_same('9223372036854775808', Measurement::sum('big'));
        $this->assert_same('0', Measurement::where('label', 'zzz')->sum('amount'));
    }

    // MIN and MAX of a numeric are the column's own strings, its scale kept; the SUM and the AVG of a decimal keep the
    // scale of the SUM, trailing zeros included (ExactDecimal::parse() drops them: 2.50 has the fraction 5)
    public function test_decimal_aggregates_keep_the_scale(): void
    {
        $this->assert_same('0.01', Measurement::min('amount'));
        $this->assert_same('1234567890123.45', Measurement::max('amount'));
        $this->assert_same('617283945061.730000', Measurement::average('amount'));

        $this->conn->query("INSERT INTO measurements (amount, label) VALUES ('2.50', 'd'), ('-0.01', 'e'), ('0.00', 'f')");
        $label = fn(string $label): ActiveRecord\Query => Measurement::where('label', $label);

        $this->assert_same('2.50', $label('d')->sum('amount'));
        $this->assert_same('2.50', $label('d')->min('amount'));
        $this->assert_same('2.50', $label('d')->max('amount'));
        $this->assert_same('2.500000', $label('d')->avg('amount'));
        $this->assert_same('-0.01', $label('e')->sum('amount'));
        $this->assert_same('-0.010000', $label('e')->avg('amount'));
        $this->assert_same('0.00', $label('f')->sum('amount'));
        $this->assert_same('0.000000', $label('f')->avg('amount'));

        // a windowed query reads its decimals the same way: the subquery keeps the column's type
        $first = fn(): ActiveRecord\Query => Measurement::order_by('id')->limit(1);
        $this->assert_same('1234567890123.45', $first()->sum('amount'));
        $this->assert_same('1234567890123.45', $first()->max('amount'));
        $this->assert_same('1234567890123.450000', $first()->avg('amount'));

        // nothing but NULLs: the SUM is '0' and there is no average
        Measurement::create(['qty' => 2, 'label' => 'c']);
        $this->assert_same('0', $label('c')->sum('amount'));
        $this->assert_null($label('c')->avg('amount'));
        $this->assert_null($label('c')->max('amount'));
    }

    // Beyond what a float keeps (15 to 17 digits): numeric(20,2) holds 18 integer digits, and a SUM of them has more
    public function test_decimals_beyond_the_precision_of_a_float_are_exact(): void
    {
        $this->conn->query('DELETE FROM measurements');

        foreach (range(1, 3) as $i) {
            $this->conn->query("INSERT INTO measurements (amount, label) VALUES ('999999999999999999.99', 'x')");
        }

        $this->assert_same('999999999999999999.99', Measurement::min('amount'));
        $this->assert_same('2999999999999999999.97', Measurement::sum('amount'));
        $this->assert_same('999999999999999999.990000', Measurement::avg('amount'));
    }

    // A SUM of bigints beyond the int range is the exact digits, on both sides of it, and the AVG of it exact; the MIN and
    // the MAX stay ints. The expected values are those of exact integer arithmetic (Python).
    public function test_sums_beyond_the_int_range_are_exact_digits(): void
    {
        foreach ([
            [PHP_INT_MAX, 1, '9223372036854775808', '4611686018427387904.0000'],
            [PHP_INT_MIN, -1, '-9223372036854775809', '-4611686018427387904.5000'],
            [PHP_INT_MAX, PHP_INT_MAX, '18446744073709551614', '9223372036854775807.0000'],
            [PHP_INT_MIN, PHP_INT_MIN, '-18446744073709551616', '-9223372036854775808.0000'],
        ] as [$first, $second, $sum, $average]) {
            foreach ([1 => $first, 2 => $second] as $id => $big) {
                $values = [$big, $id];
                $this->conn->query('UPDATE measurements SET big = ? WHERE id = ?', $values);
            }

            $this->assert_same($sum, Measurement::sum('big'), "sum $first + $second");
            $this->assert_same($average, Measurement::avg('big'), "avg $first + $second");
            $this->assert_same(max($first, $second), Measurement::max('big'), "max $first + $second");
            $this->assert_same(min($first, $second), Measurement::min('big'), "min $first + $second");
        }

        // the signed bigint of big_ids: 1 + PHP_INT_MAX
        $this->assert_same('9223372036854775808', BigId::sum('id'));
        $this->assert_same('4611686018427387904.0000', BigId::avg('id'));
        $this->assert_same(PHP_INT_MAX, BigId::max('id'));
    }

    // A wrapped query keeps the select of its own, so an expression can carry the name of a numeric column. A bigint that no
    // float holds (9007199254740993 is the first) stays its exact digits, where reading it as a float would make it
    // 9007199254740992.0. The SUM of INTs is a bigint, which PDO returns as an int, and the average of it is exact too.
    public function test_an_int_under_the_name_of_a_numeric_column_stays_exact(): void
    {
        $big = fn(): ActiveRecord\Query => Measurement::select_raw('9007199254740993::bigint AS amount')->limit(5);

        $this->assert_same('9007199254740993', $big()->max('amount'));
        $this->assert_same('9007199254740993', $big()->min('amount'));
        // the SUM of bigints is a numeric
        $this->assert_same('18014398509481986', $big()->sum('amount'));
        $this->assert_same('9007199254740993.0000', $big()->avg('amount'));

        // the SUM of ints is a bigint: an int
        $seven = fn(): ActiveRecord\Query => Measurement::select_raw('7 AS amount')->limit(5);
        $this->assert_same('7', $seven()->max('amount'));
        $this->assert_same('14', $seven()->sum('amount'));
        $this->assert_same('7.0000', $seven()->avg('amount'));
    }

    // A column that is not the model's has no type to read by, so its aggregates are the driver's values: Postgres's SUM
    // of INTs is a bigint, which PDO returns as an int, and its AVG a numeric, a string of 16 decimals
    public function test_a_column_that_is_not_the_models_is_the_value_postgres_returns(): void
    {
        $this->assert_same(3, Book::joins('author')->sum('authors.author_id'));
        $this->assert_same('1.5000000000000000', Book::joins('author')->avg('authors.author_id'));
        $this->assert_same(1, Book::joins('author')->min('authors.author_id'));
        $this->assert_same(2, Book::joins('author')->max('authors.author_id'));
    }

    // An expression that the select names has no model column either: Postgres types a SUM of an expression of INTs as a bigint, an
    // int to PDO, its AVG as a numeric, a string of 16 decimals, and its MIN and MAX as ints. qty is 1 and 2, so doubled is 2 and 4.
    public function test_an_expression_alias_gives_the_values_postgres_returns(): void
    {
        $doubled = fn(): ActiveRecord\Query => Measurement::select_raw('qty * 2 AS doubled')->order_by('id')->limit(10);

        $this->assert_same(6, $doubled()->sum('doubled'));
        $this->assert_same('3.0000000000000000', $doubled()->avg('doubled'));
        $this->assert_same('3.0000000000000000', $doubled()->average('doubled'));
        $this->assert_same(2, $doubled()->min('doubled'));
        $this->assert_same(4, $doubled()->max('doubled'));
        // no row: NULL, where a column of the model has a 0 of its type
        $this->assert_null($doubled()->where('label', 'zzz')->sum('doubled'));
        $this->assert_null($doubled()->where('label', 'zzz')->avg('doubled'));
    }

    // A float8 holds NaN and the infinities, which Postgres writes as text ('NaN', 'Infinity', '-Infinity') where PHP 8.3's
    // driver gives a float8 as a string: (float) 'NaN' would be 0.0. PHP 8.5's driver gives a float: both are read as the
    // special value.
    public function test_a_floating_column_reads_nan_and_the_infinities(): void
    {
        $this->conn->query("INSERT INTO measurements (ratio, label) VALUES ('NaN', 'nan'), ('Infinity', 'inf'), ('-Infinity', 'ninf')");

        $this->assert_true(is_nan($this->strict_call(fn() => Measurement::where('label', 'nan')->max('ratio'))));
        $this->assert_true(is_nan($this->strict_call(fn() => Measurement::where('label', 'nan')->sum('ratio'))));
        $this->assert_true(is_nan($this->strict_call(fn() => Measurement::where('label', 'nan')->avg('ratio'))));
        $this->assert_same(INF, $this->strict_call(fn() => Measurement::where('label', 'inf')->max('ratio')));
        $this->assert_same(INF, $this->strict_call(fn() => Measurement::where('label', 'inf')->sum('ratio')));
        $this->assert_same(-INF, $this->strict_call(fn() => Measurement::where('label', 'ninf')->min('ratio')));
        $this->assert_same(-INF, $this->strict_call(fn() => Measurement::where('label', 'ninf')->avg('ratio')));
    }

    // A boolean column aggregates as an integer, which Postgres has no SUM, AVG, MIN or MAX of (it has bool_and() and
    // bool_or()): its error, undefined function (42883), surfaces as a DatabaseException. The avg() of an integer column is
    // asked as its SUM, which is the function that is named.
    public function test_a_boolean_column_has_no_aggregate_on_postgres(): void
    {
        foreach (['sum' => 'sum', 'avg' => 'sum', 'average' => 'sum', 'min' => 'min', 'max' => 'max'] as $method => $function) {
            $this->assert_exception_message_contains("function $function(boolean) does not exist", fn() => Venue::$method('is_available'), ActiveRecord\DatabaseException::class);
        }

        // a boolean that none of the six venues is, and no row: the statement is the same, and so is the error
        $this->assert_exception_message_contains('function sum(boolean) does not exist', fn() => Venue::where('name', 'zzz')->sum('is_retired'), ActiveRecord\DatabaseException::class);
    }

    // A bytea comes from the driver as a stream, read into the string of its bytes as MySQL and SQLite give a blob. Postgres has
    // MIN and MAX of a bytea from version 18 only: earlier ones refuse it, undefined function (42883), a DatabaseException, and
    // AggregateCasterTest pins the stream on any version. The payload is the byte of each id: 1 and 2.
    public function test_the_min_and_max_of_a_bytea_are_its_bytes(): void
    {
        $payload = fn(): ActiveRecord\Query => Measurement::select_raw("decode(lpad(to_hex(id), 2, '0'), 'hex') AS payload")->order_by('id')->limit(5);

        if (!(bool) $this->conn->query("SELECT to_regprocedure('max(bytea)') IS NOT NULL")->fetch(PDO::FETCH_NUM)[0]) {
            $this->assert_exception_message_contains('function max(bytea) does not exist', fn() => $payload()->max('payload'), ActiveRecord\DatabaseException::class);
            $this->assert_exception_message_contains('function min(bytea) does not exist', fn() => $payload()->min('payload'), ActiveRecord\DatabaseException::class);

            return;
        }

        $this->assert_same("\x02", $payload()->max('payload'));
        $this->assert_same("\x01", $payload()->min('payload'));
        $this->assert_null($payload()->where('label', 'zzz')->max('payload'));
    }

    // A numeric holds NaN and, from PostgreSQL 14, the infinities (not numeric(20,2), which has no room for them). Their SUM, MIN,
    // MAX and AVG are the values as text, which is how Postgres returns them, and the average does not try to divide them: the
    // exact division would refuse them, with a message of its own. An infinity and its opposite make NaN.
    public function test_a_numeric_nan_and_the_infinities_are_their_own_average(): void
    {
        $this->conn->query("INSERT INTO measurements (amount, label) VALUES ('NaN', 'nan')");

        foreach (['sum', 'min', 'max', 'avg', 'average'] as $method) {
            $this->assert_same('NaN', Measurement::where('label', 'nan')->$method('amount'), "NaN, $method");
        }

        // an expression that an alias gives the name of the column; two rows of it
        foreach (['Infinity', '-Infinity'] as $infinity) {
            $infinite = fn(): ActiveRecord\Query => Measurement::select_raw("'$infinity'::numeric AS amount")->limit(5);

            foreach (['sum', 'min', 'max', 'avg', 'average'] as $method) {
                $this->assert_same($infinity, $infinite()->$method('amount'), "$infinity, $method");
            }
        }

        $both = fn(): ActiveRecord\Query => Measurement::select_raw("CASE id WHEN 1 THEN 'Infinity'::numeric ELSE '-Infinity'::numeric END AS amount")->limit(5);
        $this->assert_same('Infinity', $both()->max('amount'));
        $this->assert_same('-Infinity', $both()->min('amount'));
        $this->assert_same('NaN', $both()->sum('amount'));
        $this->assert_same('NaN', $both()->avg('amount'));

        // a finite value beside NaN is NaN too, and no row has no average
        $this->assert_same('NaN', Measurement::where_in('label', ['nan', 'a'])->avg('amount'));
        $this->assert_null(Measurement::where('label', 'zzz')->avg('amount'));
    }

    // Postgres writes its window as LIMIT n OFFSET m, with an offset alone as OFFSET m (#130)
    public function test_the_window_is_limit_and_offset(): void
    {
        $this->assert_same('SELECT * FROM "authors" LIMIT 2 OFFSET 0', Author::limit(2)->to_sql());
        $this->assert_same('SELECT * FROM "authors" LIMIT 2 OFFSET 1', Author::limit(2)->offset(1)->to_sql());
        $this->assert_same('SELECT * FROM "authors" OFFSET 1', Author::offset(1)->to_sql());
        $this->assert_same('SELECT * FROM "authors" LIMIT 2 OFFSET 2', Author::for_page(2, 2)->to_sql());
        $this->assert_same('SELECT * FROM "authors" LIMIT 0 OFFSET 0', Author::limit(0)->to_sql());
        $this->assert_same('SELECT * FROM "authors" LIMIT 1 OFFSET 9223372036854775806', Author::for_page(PHP_INT_MAX, 1)->to_sql());
    }
}
