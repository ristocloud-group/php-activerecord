<?php

use ActiveRecord\Column;

/**
 * The query builder on MySQL: what its adapter writes its own way. MariadbQueryBuilderTest runs the same
 * tests on MariaDB, which shares the adapter.
 *
 * Fixture data: authors 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob; charset_words, whose
 * latin1_word, utf8mb3_word and utf8mb4_word all hold 1 Café and 2 école; measurements 1 (qty 1, big 2^62, amount
 * 1234567890123.45, ratio 0.1) and 2 (qty 2, big 2^62, amount 0.01, ratio 0.2); big_ids 1 and PHP_INT_MAX.
 */
class MysqlQueryBuilderTest extends DatabaseTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name ?? 'mysql');
    }

    /**
     * The ids of the models, sorted.
     *
     * @param list<ActiveRecord\Model> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        $ids = array_map(fn(ActiveRecord\Model $model): int => (int) $model->id, $models);
        sort($ids);

        return $ids;
    }

    // Spec §5.2: a case-sensitive LIKE compares characters in a binary collation; the pattern is bound as given
    public function test_case_sensitive_like_converts_the_pattern_to_a_binary_collation(): void
    {
        $this->assert_sql_has('name LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_bin', Author::where_like('name', 'T%', true)->to_sql());
        $this->assert_same(['T%'], Author::where_like('name', 'T%', true)->get_bindings());
        $this->assert_same(1, count(Author::where_like('name', 'T%', true)->get()));
        $this->assert_same(0, count(Author::where_like('name', 't%', true)->get()));
    }

    // A case-insensitive LIKE is the column's collation's
    public function test_like_and_not_like(): void
    {
        $this->assert_same('SELECT * FROM `authors` WHERE `name` LIKE ?', Author::where_like('name', 't%')->to_sql());
        $this->assert_same('SELECT * FROM `authors` WHERE `name` NOT LIKE ?', Author::where_not_like('name', 't%')->to_sql());
        $this->assert_same('SELECT * FROM `authors` WHERE `name` NOT LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_bin', Author::where_not_like('name', 'T%', true)->to_sql());

        $this->assert_same(1, count(Author::where_like('name', 't%')->get()));
        $this->assert_same(3, count(Author::where_not_like('name', 'T%', true)->get()));
    }

    // A case-sensitive LIKE is right whatever the character set of the column, without a warning: an accented
    // letter matches itself and not its other case, and _ is one character. CAST(? AS BINARY) compared bytes,
    // which failed 'Café', 'é%' and '%é' on latin1, and 'Caf_' and '_cole' on utf8mb3 and utf8mb4.
    public function test_case_sensitive_like_in_every_character_set(): void
    {
        $patterns = ['Caf%' => [1], 'caf%' => [], 'Café' => [1], 'café' => [], 'CAFÉ' => [], 'é%' => [2], 'É%' => [], 'Caf_' => [1], '_cole' => [2], '%é' => [1]];

        foreach (['latin1_word', 'utf8mb3_word', 'utf8mb4_word'] as $column) {
            foreach ($patterns as $pattern => $ids) {
                $this->assert_same($ids, $this->ids(CharsetWord::where_like($column, $pattern, true)->get()), "$column LIKE '$pattern'");
                $this->assert_same([], $this->conn->query('SHOW WARNINGS')->fetchAll(), "$column LIKE '$pattern' warns");
                $this->assert_same(array_values(array_diff([1, 2], $ids)), $this->ids(CharsetWord::where_not_like($column, $pattern, true)->get()), "$column NOT LIKE '$pattern'");
                $this->assert_same([], $this->conn->query('SHOW WARNINGS')->fetchAll(), "$column NOT LIKE '$pattern' warns");
            }
        }
    }

    // Spec §5.2: the operator Laravel's MySQL grammar adds, SOUNDS LIKE, in any case; Postgres's are refused
    public function test_the_adapters_own_operators(): void
    {
        $this->assert_same(['sounds like'], $this->conn->extra_operators());
        $this->assert_same('SELECT * FROM `authors` WHERE `name` SOUNDS LIKE ?', Author::where('name', 'Sounds Like', 'Teeto')->to_sql());
        // Tito and Teeto have one SOUNDEX, T300
        $this->assert_same(['Tito'], array_map(fn(Author $author): string => $author->name, Author::where('name', 'sounds like', 'Teeto')->get()));

        foreach (['is distinct from', '@>', '&&', '?|'] as $operator) {
            $this->assert_exception_message_contains("Unknown operator '$operator' in where() on Author", fn() => Author::where('name', $operator, 'x'), ActiveRecord\ActiveRecordException::class);
        }
    }

    // A # opens a line comment on MySQL and MariaDB: SQL written by hand that holds one is followed by a newline
    // (QueryCompiler::raw_fragment()), so the comment ends with it and the ORDER BY and LIMIT after it are run
    public function test_a_hash_comment_in_raw_sql_ends_with_the_fragment(): void
    {
        $ids = fn(array $authors): array => array_map(fn(Author $author): int => $author->author_id, $authors);

        $this->assert_same([1], $ids(Author::where_raw('author_id > 0 # c')->order_by('author_id')->limit(1)->get()));
        $this->assert_same([4], $ids(Author::order_by_raw('author_id DESC # c')->limit(1)->get()));

        // and a limited write writes one row, where the comment had it write every row the WHERE matched
        $this->assert_same(1, Author::where_raw('author_id > 0 # c')->order_by('author_id')->limit(1)->delete());
        $this->assert_same([2, 3, 4], $ids(Author::order_by('author_id')->get()));
    }

    // A ; in a # comment, or in an identifier in backticks, is no statement terminator: the SQL is sent, and the comment
    // and the identifier are read as MySQL and MariaDB read them (a ; outside them is refused at the call, QueryWhereTest)
    public function test_a_semicolon_in_a_hash_comment_or_a_backtick_identifier_is_sent(): void
    {
        $this->assert_same(1, Author::where_raw('author_id > 0 # a;b')->order_by('author_id')->limit(1)->delete());
        $this->assert_same(['2'], array_map(fn(Author $author): string => (string) $author->{'a;b'}, Author::select_raw('author_id AS `a;b`')->order_by('author_id')->limit(1)->get()));
    }

    // MySQL and MariaDB read a statement terminator as they read SQL (QueryCompiler::refuse_statement_terminator()), in
    // the default sql_mode: strings in single and double quotes with backslash escapes, identifiers in backticks, a #
    // comment, a -- comment only where a space or a control character follows the dashes, and no dollar quote ($ is a
    // character of identifiers and of variables, @a$$). A block comment does not nest, and the text of an executable one
    // (/*! */, /*M! */) is SQL they run. Each ; below that they read outside is refused at the call, and its limited delete
    // sends nothing: 0--1 is 0 - -1, and the delete deleted three of the four authors; @a$$ ... @b$$ deleted all four
    public function test_a_statement_terminator_is_read_as_mysql_and_mariadb_read_it(): void
    {
        foreach (["name = 'a\\';b'", 'name = "a\\";b"', 'name = `a;b`', 'author_id > 0 # ;', 'author_id > 0 -- ;', "author_id > 0 --\t;", 'author_id > 0 /*+ ; */'] as $sql) {
            $this->assert_sql_has($sql, Author::where_raw($sql)->to_sql());
        }

        Author::table();

        foreach ([
            'author_id > 0--1;',
            'name != $$a;b$$',
            'author_id > 0 OR @a$$ IS NULL; @b$$',
            'author_id > 0 /* /* */ ; */',
            'author_id > 0 /*! ; */',
            'author_id > 0 /*M! ; */',
        ] as $sql) {
            $this->assert_same(
                [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => "SQL '$sql' in where_raw() on Author holds a statement terminator (;) outside its strings and comments: MySQL, MariaDB and SQLite would end the statement there and drop the clauses after it, and Postgres would refuse it. Bind a value with a ? marker"]],
                GoldenStatements::capture(fn() => Author::where_raw($sql)->order_by('author_id')->limit(1)->delete()),
                $sql
            );
        }

        $this->assert_same(4, (int) Author::count());
    }

    // A block comment left open is read as MySQL and MariaDB read it (QueryCompiler::refuse_statement_terminator()): it
    // does not nest, so /* /* */ is closed, and an executable one (/*! */, /*M! */) is open until its */. A # comment, and
    // a string in which a backslash escapes the quote, hold a /*. The fragments accepted here are sent, and their limited
    // delete writes one row; an open one is refused at the call, by the builder and by the legacy conditions alike, where
    // these databases refused the statement
    public function test_a_block_comment_left_open_is_read_as_mysql_and_mariadb_read_it(): void
    {
        foreach (['author_id > 0 /* /* */', 'author_id > 0 /*! AND 1 */', 'author_id > 0 /*M! AND 1 */', "name != 'a\\'/*' # /*"] as $sql) {
            $this->assert_same(1, Author::where_raw($sql)->order_by('author_id')->limit(1)->delete(), $sql);
        }

        Author::table();

        foreach (['author_id > 0 /*! AND 1', 'author_id > 0 /*M! AND 1', 'author_id > 0 /*+ hint'] as $sql) {
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

        $this->assert_same(0, (int) Author::count());
    }

    // Spec §5.2: in_random_order() is RAND() on MySQL and MariaDB, through the adapter's random_function(); the
    // order is an order like any other, and the database runs it
    public function test_in_random_order_is_rand(): void
    {
        $this->assert_same('RAND()', $this->conn->random_function());
        $this->assert_same('SELECT * FROM `authors` ORDER BY RAND()', Author::in_random_order()->to_sql());
        $this->assert_same('SELECT * FROM `authors` ORDER BY `name` ASC, RAND()', Author::order_by('name')->in_random_order()->to_sql());
        $this->assert_same([1, 2, 3, 4], $this->ids(Author::in_random_order()->get()));
    }

    // Review Focus 3: a BIGINT UNSIGNED beyond PHP_INT_MAX stays exact (#44), in the key a find looks up and in the
    // value read back, as an attribute keeps it: the digits, in a string
    public function test_bigint_unsigned_stays_exact(): void
    {
        BigId::create(['id' => '18446744073709551615', 'note' => 'max']);
        $this->assert_same('18446744073709551615', BigId::new_query()->find('18446744073709551615')->id);
        $this->assert_same('18446744073709551615', BigId::where('note', 'max')->value('id'));
    }

    // MySQL and MariaDB read an alias of the select in a HAVING, so count() and exists() keep the select of a query
    // that has a HAVING (spec §5.3). Postgres refuses the alias there (42703), get() included. Parent 2 is the one of
    // more than one author.
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

    // to_raw_sql() writes each value as the adapter binds it (spec §4.2), and PDO sends MySQL and MariaDB every value as
    // text: the raw text is the quoted text, an int and a bool included, which they convert as they convert the bound
    // one, so the rows are the bound query's, a function of a column and the alias of a HAVING among them
    public function test_the_raw_sql_writes_every_value_as_quoted_text(): void
    {
        foreach ([5, -7, true, false, 2.5, '5', null] as $value) {
            $this->assert_false($this->conn->binds_as_integer($value));
        }

        $this->assert_same(
            "SELECT * FROM `authors` WHERE `author_id` = '2' AND `parent_author_id` IN('1','2') AND `name` != '0'",
            Author::where('author_id', 2)->where_in('parent_author_id', [1, 2])->where('name', '!=', false)->to_raw_sql()
        );

        $groups = fn(int $more_than): ActiveRecord\Query => Author::select_raw('COUNT(*) AS c')->group_by('parent_author_id')->having('c', '>', $more_than);

        foreach ([1 => 1, 0 => 3, 2 => 0] as $more_than => $found) {
            $this->assert_same($found, count($groups($more_than)->get()), "bound, c > $more_than");
            $this->assert_same($found, count($this->conn->query($groups($more_than)->to_raw_sql())->fetchAll()), "raw, c > $more_than");
        }

        $this->assert_same("SELECT COUNT(*) AS c FROM `authors` GROUP BY `parent_author_id` HAVING `c` > '1'", $groups(1)->to_raw_sql());

        $length = Author::where_raw('length(name) = ?', [14]);
        $this->assert_same(1, count($length->get()));
        $this->assert_same("SELECT * FROM `authors` WHERE length(name) = '14'", $length->to_raw_sql());
        $this->assert_same(1, count($this->conn->query($length->to_raw_sql())->fetchAll()));
    }

    // Spec §5.4, §10: the raw types this adapter reports for the columns of the measurements, which classify their
    // aggregates. MySQL and MariaDB give the first word of SHOW COLUMNS' type: 'decimal' for DECIMAL, NUMERIC, DEC and
    // FIXED, 'double' for DOUBLE, REAL and DOUBLE PRECISION, 'float' for FLOAT (single, a Column::DECIMAL like the others).
    public function test_the_raw_types_of_the_measurements(): void
    {
        $this->assert_same(
            ['id' => [Column::INTEGER, 'int'], 'qty' => [Column::INTEGER, 'int'], 'big' => [Column::INTEGER, 'bigint'], 'amount' => [Column::DECIMAL, 'decimal'], 'ratio' => [Column::DECIMAL, 'double'], 'single' => [Column::DECIMAL, 'float'], 'label' => [Column::STRING, 'varchar']],
            array_map(fn(Column $column): array => [$column->type, $column->raw_type], Measurement::table()->columns)
        );
        $this->assert_same([Column::INTEGER, 'bigint'], [BigId::table()->columns['id']->type, BigId::table()->columns['id']->raw_type]);
    }

    // The aggregates of a DECIMAL column are the exact strings MySQL and MariaDB return, and a SUM of BIGINTs beyond
    // PHP_INT_MAX its exact digits (MySQL sums into a DECIMAL: no float, no overflow). The AVG is exact, at the scale of
    // the SUM plus four: DECIMAL(20,2) gives 6.
    public function test_exact_decimal_and_big_sums(): void
    {
        $this->assert_same('1234567890123.46', Measurement::sum('amount'));
        $this->assert_same('617283945061.730000', Measurement::avg('amount'));
        $this->assert_same('9223372036854775808', Measurement::sum('big'));
        $this->assert_same('0', Measurement::where('label', 'zzz')->sum('amount'));
    }

    // MIN and MAX of a DECIMAL are the column's own strings, its scale kept; the SUM and the AVG of a decimal keep the
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

    // Beyond what a float keeps (15 to 17 digits): DECIMAL(20,2) holds 18 integer digits, and a SUM of them has more
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

    // A SUM of BIGINTs beyond the int range is the exact digits, on both sides of it, and the AVG of it exact; the MIN and
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
    }

    // A wrapped query keeps the select of its own, so an expression can carry the name of a DECIMAL column. A BIGINT that no
    // float holds (9007199254740993 is the first) stays its exact digits, where reading it as a float would make it
    // 9007199254740992.0.
    public function test_an_int_under_the_name_of_a_decimal_column_stays_exact(): void
    {
        $big = fn(): ActiveRecord\Query => Measurement::select_raw('9007199254740993 AS amount')->limit(5);

        $this->assert_same('9007199254740993', $big()->max('amount'));
        $this->assert_same('9007199254740993', $big()->min('amount'));
        $this->assert_same('18014398509481986', $big()->sum('amount'));
        $this->assert_same('9007199254740993.0000', $big()->avg('amount'));

        $seven = fn(): ActiveRecord\Query => Measurement::select_raw('7 AS amount')->limit(5);
        $this->assert_same('7', $seven()->max('amount'));
        $this->assert_same('14', $seven()->sum('amount'));
        $this->assert_same('7.0000', $seven()->avg('amount'));
    }

    // A boolean column (tinyint(1)) aggregates as an integer: sum() counts the trues, as an int where MySQL's SUM is a DECIMAL
    // string; avg() is exact; min() and max() are the ints 0 and 1. The six venues are all available, and none is retired.
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

    // A column that is not the model's has no type to read by, so its aggregates are the driver's values: MySQL's SUM of
    // INTs is a DECIMAL, which PDO returns as a string, where the model's own column is an int
    public function test_a_column_that_is_not_the_models_is_the_string_mysql_returns(): void
    {
        $this->assert_same('3', Book::joins('author')->sum('authors.author_id'));
        $this->assert_same(3, Book::joins('author')->sum('author_id'));
        $this->assert_same('1.5000', Book::joins('author')->avg('authors.author_id'));
        $this->assert_same(1, Book::joins('author')->min('authors.author_id'));
        $this->assert_same(2, Book::joins('author')->max('authors.author_id'));
    }

    // An expression that the select names has no model column either: MySQL types a SUM or an AVG of an expression of INTs as
    // a DECIMAL, a string, and its MIN and MAX as ints. qty is 1 and 2, so doubled is 2 and 4.
    public function test_an_expression_alias_gives_the_values_mysql_returns(): void
    {
        $doubled = fn(): ActiveRecord\Query => Measurement::select_raw('qty * 2 AS doubled')->order_by('id')->limit(10);

        $this->assert_same('6', $doubled()->sum('doubled'));
        $this->assert_same('3.0000', $doubled()->avg('doubled'));
        $this->assert_same('3.0000', $doubled()->average('doubled'));
        $this->assert_same(2, $doubled()->min('doubled'));
        $this->assert_same(4, $doubled()->max('doubled'));
        // no row: NULL, where a column of the model has a 0 of its type
        $this->assert_null($doubled()->where('label', 'zzz')->sum('doubled'));
        $this->assert_null($doubled()->where('label', 'zzz')->avg('doubled'));
    }

    // Review Focus 3: a BIGINT UNSIGNED beyond PHP_INT_MAX stays exact (#44), in a MAX, a SUM and the AVG of it: the
    // digits, in a string. The expected values are those of exact integer arithmetic (Python).
    public function test_max_of_unsigned_bigint_beyond_int_max(): void
    {
        BigId::create(['id' => '18446744073709551615', 'note' => 'max']);
        $this->assert_same('18446744073709551615', BigId::max('id'));
        $this->assert_same(1, BigId::min('id'));
        // 1 + PHP_INT_MAX + 18446744073709551615
        $this->assert_same('27670116110564327423', BigId::sum('id'));
        $this->assert_same('9223372036854775807.6667', BigId::avg('id'));
        $this->assert_same(9223372036854775807, BigId::where('note', 'int max')->max('id'));
    }

    // MySQL has no OFFSET without LIMIT: the window of the builder is the adapter's own, an offset alone going with
    // the largest row count (#130), and the largest integers are exact in it
    public function test_the_window_is_limit_with_an_offset_before_the_count(): void
    {
        $this->assert_same('SELECT * FROM `authors` LIMIT 2', Author::limit(2)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 1,2', Author::limit(2)->offset(1)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 1, 18446744073709551615', Author::offset(1)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 2,2', Author::for_page(2, 2)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 0', Author::limit(0)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 9223372036854775806,1', Author::for_page(PHP_INT_MAX, 1)->to_sql());
    }
}
