<?php

/**
 * The query builder on Postgres: what its adapter writes its own way.
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
