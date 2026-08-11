<?php

use ActiveRecord\Column;

require_once __DIR__ . '/../lib/adapters/PgsqlAdapter.php';

class PgsqlAdapterTest extends AdapterTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('pgsql');
    }

    public function test_insert_id()
    {
        $this->conn->query("INSERT INTO authors(author_id,name) VALUES(nextval('authors_author_id_seq'),'name')");
        $this->assert_true($this->conn->insert_id('authors_author_id_seq') > 0);
    }

    public function test_insert_id_with_params()
    {
        $x = ['name'];
        $this->conn->query("INSERT INTO authors(author_id,name) VALUES(nextval('authors_author_id_seq'),?)", $x);
        $this->assert_true($this->conn->insert_id('authors_author_id_seq') > 0);
    }

    public function test_insert_id_should_return_explicitly_inserted_id()
    {
        $this->conn->query('INSERT INTO authors(author_id,name) VALUES(99,\'name\')');
        $this->assert_true($this->conn->insert_id('authors_author_id_seq') > 0);
    }

    public function test_set_charset()
    {
        $connection_string = ActiveRecord\Config::instance()->get_connection($this->connection_name);
        $conn = ActiveRecord\Connection::instance($connection_string . '?charset=utf8');
        $this->assert_equals("SET NAMES 'utf8'", $conn->last_query);
    }

    public function test_gh96_columns_not_duplicated_by_index()
    {
        $this->assert_equals(3, $this->conn->query_column_info("user_newsletters")->rowCount());
    }

    public function test_boolean_column_introspection()
    {
        $columns = $this->conn->columns('venues');

        $this->assert_equals('boolean', $columns['is_available']->raw_type);
        $this->assert_equals(Column::BOOLEAN, $columns['is_available']->type);
        $this->assert_equals(Column::BOOLEAN, $columns['is_retired']->type);

        // pg_get_expr() yields the textual 'true'/'false' — they must cast to
        // native bools, not survive as (truthy) strings (GH-30)
        $this->assert_same(true, $columns['is_available']->default);
        $this->assert_same(false, $columns['is_retired']->default);
    }

    public function test_max_bind_params_default()
    {
        $this->assert_equals(65535, $this->conn::$MAX_BIND_PARAMS);
    }

    public function test_upsert_conflict_clause_uses_on_conflict_excluded()
    {
        $clause = $this->conn->upsert_conflict_clause(['name', 'address'], ['city', 'phone']);

        $name  = $this->conn->quote_name('name');
        $addr  = $this->conn->quote_name('address');
        $city  = $this->conn->quote_name('city');
        $phone = $this->conn->quote_name('phone');

        $this->assert_equals(
            "ON CONFLICT ($name, $addr) DO UPDATE SET $city = EXCLUDED.$city, $phone = EXCLUDED.$phone",
            $clause
        );
    }
}
