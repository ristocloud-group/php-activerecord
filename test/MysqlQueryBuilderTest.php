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
}
