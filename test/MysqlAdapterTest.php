<?php

use ActiveRecord\Column;

require_once __DIR__ . '/../lib/adapters/MysqlAdapter.php';

class MysqlAdapterTest extends AdapterTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('mysql');
    }

    public function test_enum()
    {
        $author_columns = $this->conn->columns('authors');
        $this->assert_equals('enum', $author_columns['some_enum']->raw_type);
        $this->assert_equals(Column::STRING, $author_columns['some_enum']->type);
        $this->assert_same(null, $author_columns['some_enum']->length);
    }

    public function test_tinyint1_maps_to_boolean_by_convention()
    {
        // Rails-style convention: tinyint(1) — MySQL's own BOOLEAN DDL alias —
        // is a boolean column; its values and defaults are native PHP bools
        // (GH-30).
        $columns = $this->conn->columns('venues');

        $this->assert_equals('tinyint', $columns['is_available']->raw_type);
        $this->assert_equals(Column::BOOLEAN, $columns['is_available']->type);
        $this->assert_equals(Column::BOOLEAN, $columns['is_retired']->type);
        $this->assert_same(true, $columns['is_available']->default);
        $this->assert_same(false, $columns['is_retired']->default);
    }

    public function test_wider_tinyint_and_other_int_types_stay_integer()
    {
        // Only display width 1 means boolean: tinyint(2+) and every other int
        // type keep INTEGER semantics (GH-30 regression guard). MySQL 8.0.19+
        // reports no display width for tinyint(2+) ('tinyint'); MariaDB still
        // reports 'tinyint(2)' — both must stay INTEGER.
        $venue_columns = $this->conn->columns('venues');
        $this->assert_equals('tinyint', $venue_columns['tier']->raw_type);
        $this->assert_equals(Column::INTEGER, $venue_columns['tier']->type);
        $this->assert_same(5, $venue_columns['tier']->default);

        $author_columns = $this->conn->columns('authors');
        $this->assert_equals(Column::INTEGER, $author_columns['author_id']->type);
        $this->assert_equals(Column::INTEGER, $author_columns['parent_author_id']->type);
    }

    public function test_set_charset()
    {
        $connection_string = ActiveRecord\Config::instance()->get_connection($this->connection_name);
        $conn = ActiveRecord\Connection::instance($connection_string . '?charset=utf8');
        $this->assert_equals('SET NAMES ?', $conn->last_query);
    }

    public function test_limit_with_null_offset_does_not_contain_offset()
    {
        $ret = [];
        $sql = 'SELECT * FROM authors ORDER BY name ASC';
        $this->conn->query_and_fetch($this->conn->limit($sql, null, 1), function ($row) use (&$ret) {
            $ret[] = $row;
        });

        $this->assert_true(strpos($this->conn->last_query, 'LIMIT 1') !== false);
    }

    public function test_datetime_to_string()
    {
        $datetime = new DateTime('2009-01-01 01:01:01');
        $roundtrip = $this->conn->string_to_datetime($this->conn->datetime_to_string($datetime));
        $this->assert_equals($datetime->getTimestamp(), $roundtrip->getTimestamp());
    }

    public function test_max_bind_params_default()
    {
        $this->assert_equals(65535, $this->conn::$MAX_BIND_PARAMS);
    }

    public function test_upsert_conflict_clause_uses_on_duplicate_key_update()
    {
        // MySQL/MariaDB ignore the unique columns and use the table's indexes.
        $clause = $this->conn->upsert_conflict_clause(['ignored'], ['city', 'phone']);

        $city  = $this->conn->quote_name('city');
        $phone = $this->conn->quote_name('phone');

        $this->assert_equals(
            "ON DUPLICATE KEY UPDATE $city = VALUES($city), $phone = VALUES($phone)",
            $clause
        );
    }
}
