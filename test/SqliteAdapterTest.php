<?php

use ActiveRecord\Column;

require_once __DIR__ . '/../lib/adapters/SqliteAdapter.php';

class SqliteAdapterTest extends AdapterTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('sqlite');
    }

    public function tearDown(): void
    {
        parent::tearDown();

        @unlink(self::InvalidDb);
    }

    public function testConnectToInvalidDatabaseShouldNotCreateDbFile()
    {
        try {
            ActiveRecord\Connection::instance("sqlite://" . self::InvalidDb);
            $this->assertFalse(true);
        } catch (ActiveRecord\DatabaseException $e) {
            $this->assertFalse(file_exists(__DIR__ . "/" . self::InvalidDb));
        }
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

    public function test_gh183_sqliteadapter_autoincrement()
    {
        // defined in lowercase: id integer not null primary key
        $columns = $this->conn->columns('awesome_people');
        $this->assert_true($columns['id']->auto_increment);

        // defined in uppercase: `amenity_id` INTEGER NOT NULL PRIMARY KEY
        $columns = $this->conn->columns('amenities');
        $this->assert_true($columns['amenity_id']->auto_increment);

        // defined using int: `rm-id` INT NOT NULL
        $columns = $this->conn->columns('`rm-bldg`');
        $this->assert_false($columns['rm-id']->auto_increment);

        // defined using int: id INT NOT NULL PRIMARY KEY
        $columns = $this->conn->columns('hosts');
        $this->assert_true($columns['id']->auto_increment);
    }

    public function test_boolean_column_introspection()
    {
        $columns = $this->conn->columns('venues');

        $this->assert_equals('boolean', $columns['is_available']->raw_type);
        $this->assert_equals(Column::BOOLEAN, $columns['is_available']->type);
        $this->assert_equals(Column::BOOLEAN, $columns['is_retired']->type);

        // pragma table_info reports the literal default expression text
        // ('true'/'false') — it must cast to native bools (GH-30)
        $this->assert_same(true, $columns['is_available']->default);
        $this->assert_same(false, $columns['is_retired']->default);
    }

    public function test_datetime_to_string()
    {
        $datetime = '2009-01-01 01:01:01';
        $this->assert_equals($datetime, $this->conn->datetime_to_string(date_create($datetime)));
    }

    public function test_date_to_string()
    {
        $datetime = '2009-01-01';
        $this->assert_equals($datetime, $this->conn->date_to_string(date_create($datetime)));
    }

    // not supported
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function test_connect_with_port() {}

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function test_query_column_info() {}

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function test_query_table_info() {}

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function test_i_have_a_default_port() {}

    public function test_max_bind_params_is_conservative()
    {
        $this->assert_equals(999, $this->conn::$MAX_BIND_PARAMS);
    }

    public function test_named_placeholder_params_bind_by_name()
    {
        // regression: the typed-binding override must not break :name params
        $params = [':id' => 1];
        $sth = $this->conn->query('SELECT name FROM authors WHERE author_id = :id', $params);
        $row = $sth->fetch();
        $this->assert_equals('Tito', $row['name']);
    }
}
