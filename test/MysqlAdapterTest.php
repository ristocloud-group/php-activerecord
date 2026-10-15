<?php

use ActiveRecord\Column;

require_once __DIR__ . '/../lib/adapters/MysqlAdapter.php';

// a model on the TINYINT(1) AUTO_INCREMENT table the GH #164 tests create
class MysqlTinyCounter extends ActiveRecord\Model
{
    public static $table_name = 'tiny_counters';
}

class MysqlAdapterTest extends AdapterTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('mysql');
    }

    public function test_gh34_limit_zero_and_offset_without_limit_sql()
    {
        Author::all(['order' => 'author_id', 'limit' => 0]);
        $this->assert_equals('SELECT * FROM `authors` ORDER BY author_id LIMIT 0', Author::table()->last_sql);

        // MySQL has no OFFSET without LIMIT: the documented "all rows" idiom is the largest row count
        Author::all(['order' => 'author_id', 'offset' => 2]);
        $this->assert_equals('SELECT * FROM `authors` ORDER BY author_id LIMIT 2, 18446744073709551615', Author::table()->last_sql);
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

    /**
     * Creates tiny_counters with an `$type NOT NULL AUTO_INCREMENT` pk and checks that
     * the pk is an integer: found, created, saved and deleted by its own row (GH #164).
     */
    private function assert_tinyint1_counter_is_an_integer(string $type): void
    {
        $rows = fn(): array => array_map(
            fn(array $row) => $row['id'] . '=' . $row['name'],
            $this->conn->query('SELECT id, name FROM tiny_counters ORDER BY id')->fetchAll()
        );

        $this->conn->query('DROP TABLE IF EXISTS tiny_counters');
        $this->conn->query("CREATE TABLE tiny_counters (id $type NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(10), flag TINYINT(1))");

        try {
            $this->conn->query("INSERT INTO tiny_counters (name) VALUES ('one'), ('two'), ('three')");

            $found = MysqlTinyCounter::find(3);
            $this->assert_same(3, $found->id);
            $found->name = 'THREE';
            $this->assert_true($found->save());
            $this->assert_same(['1=one', '2=two', '3=THREE'], $rows());

            $created = MysqlTinyCounter::create(['name' => 'four']);
            $this->assert_same(4, $created->id);
            $created->name = 'FOUR';
            $this->assert_true($created->save());
            $this->assert_same(['1=one', '2=two', '3=THREE', '4=FOUR'], $rows());

            $this->assert_true($created->delete());
            $this->assert_same(['1=one', '2=two', '3=THREE'], $rows());

            // the plain tinyint(1) column next to it is still a boolean
            $columns = $this->conn->columns('tiny_counters');
            $this->assert_same(Column::INTEGER, $columns['id']->type);
            $this->assert_same(Column::BOOLEAN, $columns['flag']->type);
        } finally {
            $this->conn->query('DROP TABLE tiny_counters');
        }
    }

    public function test_tinyint1_auto_increment_column_stays_integer()
    {
        // GH #164: the tinyint(1) convention typed the counter BOOLEAN, so every id cast
        // to true, bound as 1: find(3)->save() and a created record's writes hit row 1
        $this->assert_tinyint1_counter_is_an_integer('TINYINT(1)');
    }

    public function test_unsigned_tinyint1_auto_increment_column_stays_integer()
    {
        // MariaDB reports it as tinyint(1) unsigned; MySQL 8 drops the width of an unsigned tinyint
        $this->assert_tinyint1_counter_is_an_integer('TINYINT(1) UNSIGNED');
    }

    public function test_tinyint1_key_column_without_auto_increment_stays_boolean()
    {
        // GH #164 exempts only AUTO_INCREMENT columns from the tinyint(1) convention
        $this->conn->query('DROP TABLE IF EXISTS tiny_flag_keys');
        $this->conn->query('CREATE TABLE tiny_flag_keys (flag TINYINT(1) NOT NULL, name VARCHAR(10) NOT NULL, PRIMARY KEY(flag, name))');

        try {
            $flag = $this->conn->columns('tiny_flag_keys')['flag'];
            $this->assert_true($flag->pk);
            $this->assert_same(Column::BOOLEAN, $flag->type);
        } finally {
            $this->conn->query('DROP TABLE tiny_flag_keys');
        }
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
