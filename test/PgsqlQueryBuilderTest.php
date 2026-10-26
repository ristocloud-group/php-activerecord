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
}
