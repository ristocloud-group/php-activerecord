<?php

/**
 * The query builder on MySQL: what its adapter writes its own way. MariadbQueryBuilderTest runs the same
 * tests on MariaDB, which shares the adapter.
 */
class MysqlQueryBuilderTest extends DatabaseTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name ?? 'mysql');
    }

    // Spec §5.2: a case-sensitive LIKE is a byte comparison
    public function test_case_sensitive_like_uses_cast_binary(): void
    {
        $this->assert_sql_has('name LIKE CAST(? AS BINARY)', Author::where_like('name', 'T%', true)->to_sql());
        $this->assert_same(1, count(Author::where_like('name', 'T%', true)->get()));
        $this->assert_same(0, count(Author::where_like('name', 't%', true)->get()));
    }

    // A case-insensitive LIKE is the column's collation's, and the pattern is bound as it is given. CAST draws
    // no warning, where LIKE BINARY ? draws the deprecation warning 1287 (spec §5.2)
    public function test_like_and_not_like(): void
    {
        $this->assert_same('SELECT * FROM `authors` WHERE `name` LIKE ?', Author::where_like('name', 't%')->to_sql());
        $this->assert_same('SELECT * FROM `authors` WHERE `name` NOT LIKE ?', Author::where_not_like('name', 't%')->to_sql());
        $this->assert_same('SELECT * FROM `authors` WHERE `name` NOT LIKE CAST(? AS BINARY)', Author::where_not_like('name', 'T%', true)->to_sql());
        $this->assert_same(['T%'], Author::where_like('name', 'T%', true)->get_bindings());

        $this->assert_same(1, count(Author::where_like('name', 't%')->get()));
        $this->assert_same(3, count(Author::where_not_like('name', 'T%', true)->get()));
        $this->assert_same([], $this->conn->query('SHOW WARNINGS')->fetchAll());
    }
}
