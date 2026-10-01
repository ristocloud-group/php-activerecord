<?php

use ActiveRecord\Column;
use ActiveRecord\Connection;
use ActiveRecord\DateTime;
use ActiveRecord\DatabaseException;

class ColumnTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    /** @var Column */
    private $column;

    /** @var Connection */
    private $conn;

    public function set_up()
    {
        $this->column = new Column();
        $this->conn = ActiveRecord\ConnectionManager::get_connection(ActiveRecord\Config::instance()->get_default_connection());
    }

    public function assert_mapped_type($type, $raw_type)
    {
        $this->column->raw_type = $raw_type;
        $this->assert_equals($type, $this->column->map_raw_type());
    }

    public function assert_cast($type, $casted_value, $original_value)
    {
        $this->column->type = $type;
        $value = $this->column->cast($original_value, $this->conn);

        if ($original_value != null && ($type == Column::DATETIME || $type == Column::DATE)) {
            $this->assert_true($value instanceof DateTime);
        } else {
            $this->assert_same($casted_value, $value);
        }
    }

    public function test_map_raw_type_dates()
    {
        $this->assert_mapped_type(Column::DATETIME, 'datetime');
        $this->assert_mapped_type(Column::DATE, 'date');
    }

    public function test_map_raw_type_integers()
    {
        $this->assert_mapped_type(Column::INTEGER, 'integer');
        $this->assert_mapped_type(Column::INTEGER, 'int');
        $this->assert_mapped_type(Column::INTEGER, 'tinyint');
        $this->assert_mapped_type(Column::INTEGER, 'smallint');
        $this->assert_mapped_type(Column::INTEGER, 'mediumint');
        $this->assert_mapped_type(Column::INTEGER, 'bigint');
    }

    public function test_map_raw_type_decimals()
    {
        $this->assert_mapped_type(Column::DECIMAL, 'float');
        $this->assert_mapped_type(Column::DECIMAL, 'double');
        $this->assert_mapped_type(Column::DECIMAL, 'numeric');
        $this->assert_mapped_type(Column::DECIMAL, 'dec');
    }

    public function test_map_raw_type_booleans()
    {
        $this->assert_mapped_type(Column::BOOLEAN, 'boolean');
        $this->assert_mapped_type(Column::BOOLEAN, 'bool');
    }

    public function test_map_raw_type_strings()
    {
        $this->assert_mapped_type(Column::STRING, 'string');
        $this->assert_mapped_type(Column::STRING, 'varchar');
        $this->assert_mapped_type(Column::STRING, 'text');
    }

    public function test_map_raw_type_default_to_string()
    {
        $this->assert_mapped_type(Column::STRING, 'bajdslfjasklfjlksfd');
    }

    public function test_map_raw_type_changes_integer_to_int()
    {
        $this->column->raw_type = 'integer';
        $this->column->map_raw_type();
        $this->assert_equals('int', $this->column->raw_type);
    }

    public function test_cast()
    {
        $datetime = new DateTime('2001-01-01');
        $this->assert_cast(Column::INTEGER, 1, '1');
        $this->assert_cast(Column::INTEGER, 1, '1.5');
        $this->assert_cast(Column::DECIMAL, 1.5, '1.5');
        $this->assert_cast(Column::DATETIME, $datetime, '2001-01-01');
        $this->assert_cast(Column::DATE, $datetime, '2001-01-01');
        $this->assert_cast(Column::DATE, $datetime, $datetime);
        $this->assert_cast(Column::STRING, 'bubble tea', 'bubble tea');
    }

    public function test_cast_integer_keeps_integer_strings_beyond_the_int_range()
    {
        // (int) would clamp these to PHP_INT_MAX / PHP_INT_MIN (#44): e.g. a
        // MySQL BIGINT UNSIGNED above PHP_INT_MAX, which PDO returns as a string
        $this->assert_cast(Column::INTEGER, '18446744073709551615', '18446744073709551615');
        $this->assert_cast(Column::INTEGER, '9223372036854775808', '9223372036854775808');
        $this->assert_cast(Column::INTEGER, '-9223372036854775809', '-9223372036854775809');
        $this->assert_cast(Column::INTEGER, '+18446744073709551615', '+18446744073709551615');
        $this->assert_cast(Column::INTEGER, '00018446744073709551615', '00018446744073709551615');
        $this->assert_cast(Column::INTEGER, ' 18446744073709551615 ', ' 18446744073709551615 ');
    }

    public function test_cast_integer_casts_values_within_the_int_range_as_before()
    {
        $this->assert_cast(Column::INTEGER, PHP_INT_MAX, '9223372036854775807');
        $this->assert_cast(Column::INTEGER, PHP_INT_MIN, '-9223372036854775808');
        $this->assert_cast(Column::INTEGER, PHP_INT_MAX, '0009223372036854775807');
        $this->assert_cast(Column::INTEGER, PHP_INT_MAX, PHP_INT_MAX);
        $this->assert_cast(Column::INTEGER, 7, '007');
        $this->assert_cast(Column::INTEGER, 12, ' 12 ');
        $this->assert_cast(Column::INTEGER, 5, '+5');
        $this->assert_cast(Column::INTEGER, -5, '-5');
        $this->assert_cast(Column::INTEGER, 1000, '1e3');
        $this->assert_cast(Column::INTEGER, 12, '12abc');
        $this->assert_cast(Column::INTEGER, 0, 'abc');
        $this->assert_cast(Column::INTEGER, 0, '');
        $this->assert_cast(Column::INTEGER, 1, true);
        $this->assert_cast(Column::INTEGER, 0, false);
        $this->assert_cast(Column::INTEGER, 1, 1.9);
        $this->assert_cast(Column::INTEGER, -1, -1.9);
        $this->assert_cast(Column::INTEGER, PHP_INT_MIN, (float) PHP_INT_MIN);
        $this->assert_cast(Column::INTEGER, 9223372036854774784, 9.2233720368547748E18);
    }

    public function test_cast_integer_keeps_floats_outside_the_int_range()
    {
        // (int) would wrap these into an unrelated int (and PHP 8.5 warns)
        $this->assert_cast(Column::INTEGER, 1.8446744073709552E19, 1.8446744073709552E19);
        $this->assert_cast(Column::INTEGER, 9.2233720368547758E18, 9.2233720368547758E18);
        $this->assert_cast(Column::INTEGER, -1.0E19, -1.0E19);
        $this->assert_cast(Column::INTEGER, INF, INF);
        $this->assert_cast(Column::INTEGER, -INF, -INF);

        $this->column->type = Column::INTEGER;
        $this->assert_nan($this->column->cast(NAN, $this->conn));
    }

    public function test_cast_boolean()
    {
        // PHP bools and numeric forms
        $this->assert_cast(Column::BOOLEAN, true, true);
        $this->assert_cast(Column::BOOLEAN, false, false);
        $this->assert_cast(Column::BOOLEAN, true, 1);
        $this->assert_cast(Column::BOOLEAN, false, 0);
        $this->assert_cast(Column::BOOLEAN, true, '1');
        $this->assert_cast(Column::BOOLEAN, false, '0');
        $this->assert_cast(Column::BOOLEAN, false, '');

        // Postgres textual forms (introspected defaults arrive as text) —
        // beware (bool)'f' and (bool)'false' are TRUE in PHP, so these need
        // explicit, case-insensitive handling
        $this->assert_cast(Column::BOOLEAN, true, 't');
        $this->assert_cast(Column::BOOLEAN, false, 'f');
        $this->assert_cast(Column::BOOLEAN, true, 'true');
        $this->assert_cast(Column::BOOLEAN, false, 'false');
        $this->assert_cast(Column::BOOLEAN, true, 'TRUE');
        $this->assert_cast(Column::BOOLEAN, false, 'FALSE');
        $this->assert_cast(Column::BOOLEAN, false, ' f ');

        // other truthy values fall back to (bool)
        $this->assert_cast(Column::BOOLEAN, true, 5);
        $this->assert_cast(Column::BOOLEAN, true, 'yes');
    }

    public function test_cast_leave_null_alone()
    {
        $types = [
            Column::STRING,
            Column::INTEGER,
            Column::DECIMAL,
            Column::DATETIME,
            Column::DATE,
            Column::BOOLEAN];

        foreach ($types as $type) {
            $this->assert_cast($type, null, null);
        }
    }

    public function test_empty_and_null_date_strings_should_return_null()
    {
        $column = new Column();
        $column->type = Column::DATE;
        $this->assert_equals(null, $column->cast(null, $this->conn));
        $this->assert_equals(null, $column->cast('', $this->conn));
    }

    public function test_empty_and_null_datetime_strings_should_return_null()
    {
        $column = new Column();
        $column->type = Column::DATETIME;
        $this->assert_equals(null, $column->cast(null, $this->conn));
        $this->assert_equals(null, $column->cast('', $this->conn));
    }
}
