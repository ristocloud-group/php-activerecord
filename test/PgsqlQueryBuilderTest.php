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

    // Spec §5.2: Postgres's LIKE is case-sensitive, and ILIKE is not
    public function test_like_is_ilike_unless_case_sensitive(): void
    {
        $this->assert_sql_has('name ILIKE ?', Author::where_like('name', 't%')->to_sql());
        $this->assert_sql_has('name LIKE ?', Author::where_like('name', 'T%', true)->to_sql());
    }

    public function test_not_like_and_the_rows_found(): void
    {
        $this->assert_same('SELECT * FROM "authors" WHERE "name" NOT ILIKE ?', Author::where_not_like('name', 't%')->to_sql());
        $this->assert_same('SELECT * FROM "authors" WHERE "name" NOT LIKE ?', Author::where_not_like('name', 'T%', true)->to_sql());
        $this->assert_same(['T%'], Author::where_like('name', 'T%', true)->get_bindings());

        $this->assert_same(1, count(Author::where_like('name', 't%')->get()));
        $this->assert_same(0, count(Author::where_like('name', 't%', true)->get()));
        $this->assert_same(3, count(Author::where_not_like('name', 't%')->get()));
    }
}
