<?php

/**
 * The query builder on MySQL: what its adapter writes its own way. MariadbQueryBuilderTest runs the same
 * tests on MariaDB, which shares the adapter.
 *
 * Fixture data: authors 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob; charset_words, whose
 * latin1_word, utf8mb3_word and utf8mb4_word all hold 1 Café and 2 école.
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
