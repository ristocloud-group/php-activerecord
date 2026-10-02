<?php

use ActiveRecord\Column;

require_once __DIR__ . '/../lib/adapters/PgsqlAdapter.php';

class RmBldgExplicitSequence extends ActiveRecord\Model
{
    public static $table = 'rm-bldg';
    public static $sequence = 'rm-bldg_explicit_seq';
}

class PgsqlAdapterTest extends AdapterTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('pgsql');
    }

    public function test_hash_condition_keys_are_left_to_the_database_when_the_schema_is_unknown()
    {
        // `$db` makes the column introspection come back empty on Postgres: with no
        // known column, no key is rejected (find by pk included)
        $this->assert_equals([], PublicSchemaAuthor::table()->columns);
        $this->assert_equals(['Tito'], array_map(fn($a) => $a->name, PublicSchemaAuthor::all(['conditions' => ['name' => 'Tito']])));
        $this->assert_equals(1, PublicSchemaAuthor::count(['conditions' => ['name' => 'Tito']]));
        $this->assert_true(PublicSchemaAuthor::exists(['author_id' => 1]));
        $this->assert_equals('Tito', PublicSchemaAuthor::find(1)->name);
        $this->assert_equals(0, PublicSchemaAuthor::delete_all(['conditions' => ['name' => 'nobody']]));

        // an unknown key still fails, at the database
        try {
            PublicSchemaAuthor::all(['conditions' => ['nope' => 1]]);
            $this->fail('nope must fail at the database');
        } catch (ActiveRecord\DatabaseException $e) {
            $this->assert_false(str_starts_with($e->getMessage(), 'Unknown column'), $e->getMessage());
        }
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

    public function test_table_without_primary_key_infers_no_sequence()
    {
        // rm-bldg has no primary key, so there is no pk column to derive a
        // sequence name from: loading its table must not read a missing
        // $pk[0] (E_WARNING "Undefined array key 0") nor invent "rm-bldg__seq"
        $table = RmBldg::table();

        $this->assert_same([], $table->pk);
        $this->assert_null($table->sequence);
        $this->assert_equals('name', RmBldg::first()->rm_name);
    }

    public function test_table_without_primary_key_keeps_declared_sequence()
    {
        $this->assert_equals(RmBldgExplicitSequence::$sequence, RmBldgExplicitSequence::table()->sequence);
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
