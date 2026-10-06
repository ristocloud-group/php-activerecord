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

    public function test_gh34_limit_zero_and_offset_without_limit_sql()
    {
        Author::all(['order' => 'author_id', 'limit' => 0]);
        $this->assert_equals('SELECT * FROM "authors" ORDER BY author_id LIMIT 0 OFFSET 0', Author::table()->last_sql);

        Author::all(['order' => 'author_id', 'offset' => 2]);
        $this->assert_equals('SELECT * FROM "authors" ORDER BY author_id OFFSET 2', Author::table()->last_sql);
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

    public function test_eager_reverse_fk_through_keeps_a_target_column_that_differs_in_case_or_has_a_long_name()
    {
        $c = $this->conn;
        $drop = fn() => $c->query('DROP TABLE IF EXISTS t29_ltgts, t29_lmids, t29_tgts, t29_mids, t29_owners');
        $drop();

        try {
            $c->query('CREATE TABLE t29_owners (id int PRIMARY KEY)');
            $c->query('CREATE TABLE t29_mids (id int PRIMARY KEY, owner_ref int)');
            $c->query('CREATE TABLE t29_tgts (id int PRIMARY KEY, mid_id int, "Owner_Ref" int)');
            $c->query("CREATE TABLE t29_lmids (id int PRIMARY KEY, owner_reference_column_with_a_deliberately_long_name_x int)");
            $c->query("CREATE TABLE t29_ltgts (id int PRIMARY KEY, lmid_id int, owner_reference_column_with_a_deliberately_long_name_x int)");
            $c->query('INSERT INTO t29_owners VALUES (1), (2)');
            $c->query('INSERT INTO t29_mids VALUES (10, 1), (20, 2)');
            $c->query('INSERT INTO t29_tgts VALUES (100, 10, 7), (200, 20, 8)');
            $c->query('INSERT INTO t29_lmids VALUES (10, 1), (20, 2)');
            $c->query('INSERT INTO t29_ltgts VALUES (100, 10, 7), (200, 20, 8)');
            ActiveRecord\Table::clear_cache();

            // "Owner_Ref" is fetched as owner_ref (PDO lower-cases names), like the middle key
            $lazy = T29Owner::find(1)->t29_tgts;
            $eager = T29Owner::all(['include' => ['t29_tgts'], 'order' => 'id']);
            $this->assert_equals([100], array_map(fn($t) => $t->id, $lazy));
            $this->assert_equals([[100], [200]], array_map(fn($o) => array_map(fn($t) => $t->id, $o->t29_tgts), $eager));
            $this->assert_equals(7, $lazy[0]->owner_ref);
            $this->assert_equals(7, $eager[0]->t29_tgts[0]->owner_ref);

            // a 54-byte owner key: the private alias stays within 63 bytes (no truncation)
            $eager = T29LOwner::all(['include' => ['t29_ltgts'], 'order' => 'id']);
            $this->assert_equals([[100], [200]], array_map(fn($o) => array_map(fn($t) => $t->id, $o->t29_ltgts), $eager));
            $this->assert_equals(7, $eager[0]->t29_ltgts[0]->owner_reference_column_with_a_deliberately_long_name_x);
            $this->assert_equals(7, T29LOwner::find(1)->t29_ltgts[0]->owner_reference_column_with_a_deliberately_long_name_x);
        } finally {
            $drop();
            ActiveRecord\Table::clear_cache();
        }
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
