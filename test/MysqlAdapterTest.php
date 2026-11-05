<?php

use ActiveRecord\Column;

// a model on the TINYINT(1) AUTO_INCREMENT table the GH #164 tests create
class MysqlTinyCounter extends ActiveRecord\Model
{
    public static $table_name = 'tiny_counters';
}

// a model on the tiny_numbers table the GH #195 tests create: tinyint(1) columns holding small integers
class MysqlTinyNumber extends ActiveRecord\Model
{
    public static $table_name = 'tiny_numbers';
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

    /**
     * Runs $test with Config::set_tinyint_as_boolean($enabled) on a fresh Table cache,
     * then restores the option and clears the cache again (GH #195).
     */
    private function with_tinyint_as_boolean(bool $enabled, Closure $test): void
    {
        $config = ActiveRecord\Config::instance();
        $original = $config->get_tinyint_as_boolean();
        $config->set_tinyint_as_boolean($enabled);
        ActiveRecord\Table::clear_cache();

        try {
            $test();
        } finally {
            $config->set_tinyint_as_boolean($original);
            ActiveRecord\Table::clear_cache();
        }
    }

    public function test_tinyint1_stays_integer_when_tinyint_as_boolean_is_off()
    {
        // GH #195: a legacy schema stores small integers in tinyint(1); with the option
        // off the column is an INTEGER, as in 1.7.1, and so are its defaults
        $this->with_tinyint_as_boolean(false, function () {
            $columns = $this->conn->columns('venues');

            $this->assert_same('tinyint', $columns['is_available']->raw_type);
            $this->assert_same(Column::INTEGER, $columns['is_available']->type);
            $this->assert_same(Column::INTEGER, $columns['is_retired']->type);
            $this->assert_same(1, $columns['is_available']->default);
            $this->assert_same(0, $columns['is_retired']->default);
            $this->assert_same(Column::INTEGER, $columns['tier']->type);
        });
    }

    public function test_tinyint1_keeps_small_integers_when_tinyint_as_boolean_is_off()
    {
        // GH #195: with the convention on, 4 and -1 read as true and write back as 1;
        // with it off they survive a read, a write and serialisation as integers
        $raw = fn(): array => array_map(
            fn(array $row) => $row['id'] . ':' . $row['stato'] . ':' . $row['giorno'],
            $this->conn->query('SELECT id, stato, giorno FROM tiny_numbers ORDER BY id')->fetchAll()
        );

        $this->conn->query('DROP TABLE IF EXISTS tiny_numbers');
        $this->conn->query('CREATE TABLE tiny_numbers (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, stato TINYINT(1) NOT NULL DEFAULT 4, giorno TINYINT(1))');

        try {
            $this->with_tinyint_as_boolean(false, function () use ($raw) {
                $this->assert_same(4, (new MysqlTinyNumber())->stato);

                $created = MysqlTinyNumber::create(['stato' => -1, 'giorno' => 30]);
                $this->assert_same(['1:-1:30'], $raw());

                $found = MysqlTinyNumber::find($created->id);
                $this->assert_same(-1, $found->stato);
                $this->assert_same(30, $found->giorno);

                $found->stato = 4;
                $this->assert_true($found->save());
                $this->assert_same(['1:4:30'], $raw());

                $found = MysqlTinyNumber::find($created->id);
                $this->assert_same(['id' => 1, 'stato' => 4, 'giorno' => 30], $found->to_array());
                $this->assert_same('{"id":1,"stato":4,"giorno":30}', $found->to_json());
            });

            // the default convention reads the same row as booleans
            $this->with_tinyint_as_boolean(true, function () {
                $found = MysqlTinyNumber::find(1);
                $this->assert_same(true, $found->stato);
                $this->assert_same(true, $found->giorno);
            });
        } finally {
            $this->conn->query('DROP TABLE tiny_numbers');
        }
    }

    public function test_auto_increment_tinyint1_and_wider_tinyint_stay_integer_either_way()
    {
        // GH #195: the option changes only the plain tinyint(1); an AUTO_INCREMENT
        // tinyint(1) (GH #164) and a tinyint(4) are INTEGER with it on or off
        $this->conn->query('DROP TABLE IF EXISTS tiny_widths');
        $this->conn->query('CREATE TABLE tiny_widths (id TINYINT(1) NOT NULL AUTO_INCREMENT PRIMARY KEY, flag TINYINT(1), wide TINYINT(4))');

        try {
            foreach ([[true, Column::BOOLEAN], [false, Column::INTEGER]] as [$enabled, $flag_type]) {
                $this->with_tinyint_as_boolean($enabled, function () use ($flag_type) {
                    $columns = $this->conn->columns('tiny_widths');
                    $this->assert_same(Column::INTEGER, $columns['id']->type);
                    $this->assert_same($flag_type, $columns['flag']->type);
                    $this->assert_same(Column::INTEGER, $columns['wide']->type);
                });
            }
        } finally {
            $this->conn->query('DROP TABLE tiny_widths');
        }
    }

    public function test_schema_cache_keeps_tinyint_as_boolean_settings_apart()
    {
        // GH #195: the schema cache outlives the process, so a cached BOOLEAN must not be
        // read back once the option is off, nor a cached INTEGER once it is back on
        $dir = sys_get_temp_dir() . '/phpar-tinyint-schema-cache';
        ActiveRecord\Config::instance()->set_cache("file://$dir");

        try {
            ActiveRecord\Cache::flush();
            $type = function (): int {
                ActiveRecord\Table::clear_cache(Venue::class);
                return Venue::table()->columns['is_available']->type;
            };

            $this->assert_same(Column::BOOLEAN, $type());
            $this->with_tinyint_as_boolean(false, fn() => $this->assert_same(Column::INTEGER, $type()));
            $this->assert_same(Column::BOOLEAN, $type());
            $this->with_tinyint_as_boolean(false, fn() => $this->assert_same(Column::INTEGER, $type()));
        } finally {
            ActiveRecord\Cache::flush();
            ActiveRecord\Cache::initialize(null);
            ActiveRecord\Table::clear_cache();
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
