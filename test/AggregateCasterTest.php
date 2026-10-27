<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\AggregateCaster;
use ActiveRecord\Column;
use ActiveRecord\ExactDecimal;

/**
 * ActiveRecord\AggregateCaster: how Query reads what the database returns for the aggregates of a column (spec
 * §5.4). It needs no database: the values are given as the drivers give them, which differ by adapter (MySQL
 * returns a SUM as a DECIMAL string, Postgres a SUM of INTs as an int and of BIGINTs as a string, SQLite a REAL as a
 * float, and PHP 8.3's Postgres driver a float8 as a string where PHP 8.5's gives a float).
 *
 * The (type, raw_type) pairs are those the adapters report (see the adapter tests, which pin them against the
 * database): MySQL and MariaDB 'float', 'double' and 'decimal' for REAL, DOUBLE PRECISION, NUMERIC, DEC and FIXED
 * too, Postgres 'double' for float8, 'numeric' for decimal, and for real (float4) the pair (STRING, 'real'), SQLite
 * the first word of the declared type, REAL included (STRING, 'real').
 */
class AggregateCasterTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    private function column(int $type, string $raw_type): Column
    {
        $column = new Column();
        $column->type = $type;
        $column->raw_type = $raw_type;

        return $column;
    }

    /**
     * Asserts that $call throws an ActiveRecordException whose message holds $mention.
     */
    private function assert_refused(callable $call, string $mention, string $case): void
    {
        try {
            $call();
        } catch (ActiveRecordException $e) {
            $this->assert_true(str_contains($e->getMessage(), $mention), "$case: '$mention' is not in: {$e->getMessage()}");

            return;
        }

        $this->fail("$case: nothing was thrown");
    }

    public function test_kind_classifies_by_type_and_raw_type(): void
    {
        foreach (['int', 'bigint', 'smallint', 'tinyint', 'mediumint'] as $raw_type) {
            $this->assert_same(AggregateCaster::INTEGER, AggregateCaster::kind($this->column(Column::INTEGER, $raw_type)), $raw_type);
        }

        foreach (['decimal', 'numeric', 'dec', 'DECIMAL', 'Numeric'] as $raw_type) {
            $this->assert_same(AggregateCaster::DECIMAL, AggregateCaster::kind($this->column(Column::DECIMAL, $raw_type)), $raw_type);
        }

        // 'real' is no Column::DECIMAL: the type mapping has no such key, so the adapters report Postgres's real (float4)
        // and SQLite's REAL as Column::STRING, and the raw type alone makes them floating
        foreach ([[Column::DECIMAL, 'float'], [Column::DECIMAL, 'double'], [Column::DECIMAL, 'DOUBLE'], [Column::STRING, 'real'], [Column::STRING, 'double precision'], [Column::DECIMAL, 'double precision']] as [$type, $raw_type]) {
            $this->assert_same(AggregateCaster::FLOATING, AggregateCaster::kind($this->column($type, $raw_type)), "$type $raw_type");
        }
    }

    // A boolean is an integer: MySQL's and MariaDB's tinyint(1) and SQLite's BOOLEAN hold 0 and 1, so its sum() counts the
    // trues, its avg() is exact and its min() and max() are the ints 0 and 1 (Postgres refuses these aggregates of a boolean)
    public function test_kind_of_a_boolean_column_is_integer(): void
    {
        foreach ([[Column::BOOLEAN, 'tinyint'], [Column::BOOLEAN, 'boolean'], [Column::BOOLEAN, 'bool']] as [$type, $raw_type]) {
            $this->assert_same(AggregateCaster::INTEGER, AggregateCaster::kind($this->column($type, $raw_type)), "$type $raw_type");
            $this->assert_same(AggregateCaster::INTEGER, AggregateCaster::kind($this->column($type, $raw_type), false), "$type $raw_type, no exact decimals");
        }

        // the sum of MySQL is a DECIMAL string, which is the int of the count
        $this->assert_same(6, AggregateCaster::cast(AggregateCaster::INTEGER, 'SUM', '6'));
        $this->assert_same(0, AggregateCaster::cast(AggregateCaster::INTEGER, 'SUM', null));
    }

    // A database that stores a DECIMAL as a floating-point number (SQLite) has no exact decimal: the raw type is still
    // decimal, numeric or dec, and the column is floating. The other kinds do not change.
    public function test_kind_of_a_decimal_column_where_the_database_keeps_no_digits_is_floating(): void
    {
        foreach (['decimal', 'numeric', 'dec'] as $raw_type) {
            $this->assert_same(AggregateCaster::FLOATING, AggregateCaster::kind($this->column(Column::DECIMAL, $raw_type), false), $raw_type);
            $this->assert_same(AggregateCaster::DECIMAL, AggregateCaster::kind($this->column(Column::DECIMAL, $raw_type), true), $raw_type);
        }

        $this->assert_same(AggregateCaster::INTEGER, AggregateCaster::kind($this->column(Column::INTEGER, 'bigint'), false));
        $this->assert_same(AggregateCaster::FLOATING, AggregateCaster::kind($this->column(Column::DECIMAL, 'double'), false));
        $this->assert_same(AggregateCaster::FLOATING, AggregateCaster::kind($this->column(Column::STRING, 'real'), false));
        $this->assert_null(AggregateCaster::kind($this->column(Column::STRING, 'varchar'), false));
        $this->assert_null(AggregateCaster::kind(null, false));
    }

    // Every other column, and anything that names none, is read as the driver gives it
    public function test_kind_of_any_other_column_is_none(): void
    {
        foreach ([[Column::STRING, 'varchar'], [Column::STRING, 'text'], [Column::STRING, 'money'], [Column::STRING, 'year'], [Column::STRING, 'float8'], [Column::DATETIME, 'datetime'], [Column::DATE, 'date'], [Column::TIME, 'time']] as [$type, $raw_type]) {
            $this->assert_null(AggregateCaster::kind($this->column($type, $raw_type)), "$type $raw_type");
        }

        $this->assert_null(AggregateCaster::kind(null));
        // a column nobody gave a raw type to
        $this->assert_null(AggregateCaster::kind(new Column()));
    }

    // An integer column: an int while the exact value fits, else its digits (ExactDecimal decides on the digits)
    public function test_an_integer_column_is_an_int_or_the_exact_digits(): void
    {
        foreach (['MIN', 'MAX', 'SUM'] as $function) {
            $this->assert_same(5, AggregateCaster::cast(AggregateCaster::INTEGER, $function, 5), $function);
            // MySQL: a SUM is a DECIMAL, which PDO gives as a string
            $this->assert_same(5, AggregateCaster::cast(AggregateCaster::INTEGER, $function, '5'), $function);
            $this->assert_same(-5, AggregateCaster::cast(AggregateCaster::INTEGER, $function, '-5'), $function);
            $this->assert_same(PHP_INT_MAX, AggregateCaster::cast(AggregateCaster::INTEGER, $function, '9223372036854775807'), $function);
            $this->assert_same(PHP_INT_MIN, AggregateCaster::cast(AggregateCaster::INTEGER, $function, '-9223372036854775808'), $function);
            $this->assert_same('9223372036854775808', AggregateCaster::cast(AggregateCaster::INTEGER, $function, '9223372036854775808'), $function);
            $this->assert_same('-9223372036854775809', AggregateCaster::cast(AggregateCaster::INTEGER, $function, '-9223372036854775809'), $function);
            $this->assert_same('18446744073709551615', AggregateCaster::cast(AggregateCaster::INTEGER, $function, '18446744073709551615'), $function);
            // as ExactDecimal reads a number: a fraction of zeros and leading zeros do not matter
            $this->assert_same(5, AggregateCaster::cast(AggregateCaster::INTEGER, $function, '5.00'), $function);
            $this->assert_same('9223372036854775808', AggregateCaster::cast(AggregateCaster::INTEGER, $function, '009223372036854775808'), $function);
        }
    }

    // An integer column that holds something else than an exact integer is an anomaly, which is not hidden
    public function test_an_integer_column_refuses_a_value_that_is_not_an_exact_integer(): void
    {
        $this->assert_refused(fn() => AggregateCaster::cast(AggregateCaster::INTEGER, 'SUM', '5.5'), 'to_int_or_string', 'a fraction');
        $this->assert_refused(fn() => AggregateCaster::cast(AggregateCaster::INTEGER, 'MAX', 'abc'), 'to_int_or_string', 'text');
    }

    // A DECIMAL column: the exact string as the database returns it, the scale kept
    public function test_a_decimal_column_is_the_exact_string(): void
    {
        foreach (['MIN', 'MAX', 'SUM'] as $function) {
            foreach (['1234567890123.46', '0.01', '2.50', '-0.00', '100', '999999999999999999.99'] as $value) {
                $this->assert_same($value, AggregateCaster::cast(AggregateCaster::DECIMAL, $function, $value), "$function $value");
            }
        }
    }

    // A DECIMAL column that the driver gives as an int loses no digit. A wrapped query keeps the select of its own, so an
    // expression can carry the name of a DECIMAL column, and a float would make 9007199254740993 (the first int that a
    // float cannot hold) 9007199254740992. The int is its digits, a string of the same value, as the database's own
    // DECIMAL is; only a float is a float. (SQLite has no exact decimal: its DECIMAL columns are floating, see kind().)
    public function test_a_decimal_column_that_the_driver_gives_as_an_int_is_its_digits(): void
    {
        foreach (['MIN', 'MAX', 'SUM'] as $function) {
            $this->assert_same('12', AggregateCaster::cast(AggregateCaster::DECIMAL, $function, 12), $function);
            $this->assert_same('0', AggregateCaster::cast(AggregateCaster::DECIMAL, $function, 0), $function);
            $this->assert_same('-7', AggregateCaster::cast(AggregateCaster::DECIMAL, $function, -7), $function);
            $this->assert_same('9007199254740993', AggregateCaster::cast(AggregateCaster::DECIMAL, $function, 9007199254740993), $function);
            $this->assert_same('9223372036854775807', AggregateCaster::cast(AggregateCaster::DECIMAL, $function, PHP_INT_MAX), $function);
            $this->assert_same('-9223372036854775808', AggregateCaster::cast(AggregateCaster::DECIMAL, $function, PHP_INT_MIN), $function);
            // a float is a float
            $this->assert_same(1234567890123.46, AggregateCaster::cast(AggregateCaster::DECIMAL, $function, 1234567890123.46), $function);
            $this->assert_same(0.5, AggregateCaster::cast(AggregateCaster::DECIMAL, $function, 0.5), $function);
        }
    }

    // A floating column: a float, whatever the driver gives (PHP 8.3's Postgres driver gives a float8 as a string)
    public function test_a_floating_column_is_a_float(): void
    {
        foreach (['MIN', 'MAX', 'SUM', 'AVG'] as $function) {
            $this->assert_same(0.1, AggregateCaster::cast(AggregateCaster::FLOATING, $function, 0.1), $function);
            $this->assert_same(0.30000000000000004, AggregateCaster::cast(AggregateCaster::FLOATING, $function, '0.30000000000000004'), $function);
            $this->assert_same(3.0, AggregateCaster::cast(AggregateCaster::FLOATING, $function, 3), $function);
            $this->assert_same(-2.5, AggregateCaster::cast(AggregateCaster::FLOATING, $function, '-2.5'), $function);
            $this->assert_same(1.5e20, AggregateCaster::cast(AggregateCaster::FLOATING, $function, '1.5e+20'), $function);
        }
    }

    // Text that is no number is not made one: (float) 'abc' would be 0.0, silently
    public function test_a_floating_column_leaves_text_that_is_no_number_as_it_is(): void
    {
        foreach (['abc', '', 'nan', 'inf', '1,5'] as $text) {
            $this->assert_same($text, AggregateCaster::cast(AggregateCaster::FLOATING, 'MAX', $text), $text);
        }
    }

    // Postgres writes the values of a float that no decimal text can: (float) 'NaN' would be 0.0, silently
    public function test_a_floating_column_reads_the_special_values_postgres_writes_as_text(): void
    {
        foreach (['MIN', 'MAX', 'SUM', 'AVG'] as $function) {
            $this->assert_true(is_nan(AggregateCaster::cast(AggregateCaster::FLOATING, $function, 'NaN')), $function);
            $this->assert_same(INF, AggregateCaster::cast(AggregateCaster::FLOATING, $function, 'Infinity'), $function);
            $this->assert_same(-INF, AggregateCaster::cast(AggregateCaster::FLOATING, $function, '-Infinity'), $function);
        }
    }

    // §5.4: a SUM of no rows is NULL, and is 0 of the type of its column; a MIN, a MAX and an AVG of none are NULL
    public function test_no_row(): void
    {
        $this->assert_same(0, AggregateCaster::cast(AggregateCaster::INTEGER, 'SUM', null));
        $this->assert_same('0', AggregateCaster::cast(AggregateCaster::DECIMAL, 'SUM', null));
        $this->assert_same(0.0, AggregateCaster::cast(AggregateCaster::FLOATING, 'SUM', null));
        $this->assert_null(AggregateCaster::cast(null, 'SUM', null));

        foreach ([AggregateCaster::INTEGER, AggregateCaster::DECIMAL, AggregateCaster::FLOATING, null] as $kind) {
            foreach (['MIN', 'MAX', 'AVG'] as $function) {
                $this->assert_null(AggregateCaster::cast($kind, $function, null), "$kind $function");
            }
        }
    }

    // A column that is not the model's, an expression, a date: the driver's value, unchanged
    public function test_any_other_column_is_the_drivers_value(): void
    {
        foreach (['MIN', 'MAX', 'SUM', 'AVG'] as $function) {
            foreach ([5, '5', 5.5, '2026-01-02', 'c', 0, '', 4611686018427387904, '9223372036854775808'] as $value) {
                $this->assert_same($value, AggregateCaster::cast(null, $function, $value), "$function " . var_export($value, true));
            }
        }
    }

    // PHP coerces a float given to a parameter declared int|string before the method runs, so ExactDecimal never sees
    // the float, only the int PHP makes of it (1.5 is 1) and a deprecation. The caller must classify the float first. This
    // pins the premise, so that the tests below cannot pass because the harness misses the deprecation.
    public function test_php_turns_a_float_given_to_exact_decimal_into_an_int(): void
    {
        $this->assert_same(1, @ExactDecimal::to_int_or_string(1.5));
        $this->assert_same('1.0000', @ExactDecimal::divide(1.5, 1, 4));

        try {
            $this->strict_call(fn() => ExactDecimal::to_int_or_string(1.5));
            $this->fail('PHP raised no diagnostic for a float given to ExactDecimal');
        } catch (ErrorException $e) {
            $this->assert_true(str_contains($e->getMessage(), 'Implicit conversion from float 1.5 to int loses precision'), $e->getMessage());
        }
    }

    // A float that the driver gives for an exact column (SQLite stores a REAL in an INTEGER column too, when the value
    // has a fraction) never reaches ExactDecimal: the result is the float, whole, and PHP raises nothing
    public function test_no_float_reaches_the_exact_helper(): void
    {
        foreach ([1.5, -2.5, 0.30000000000000004, 3.0, 1.0e30, INF, -INF] as $float) {
            foreach (['MIN', 'MAX', 'SUM'] as $function) {
                $this->assert_same($float, $this->strict_call(fn() => AggregateCaster::cast(AggregateCaster::INTEGER, $function, $float)), "integer $function $float");
                $this->assert_same($float, $this->strict_call(fn() => AggregateCaster::cast(AggregateCaster::DECIMAL, $function, $float)), "decimal $function $float");
                $this->assert_same($float, $this->strict_call(fn() => AggregateCaster::cast(AggregateCaster::FLOATING, $function, $float)), "floating $function $float");
            }
        }

        $this->assert_same(1.75, $this->strict_call(fn() => AggregateCaster::average(3.5, 2)));
        $this->assert_same(3.0, $this->strict_call(fn() => AggregateCaster::average(6.0, 2)));
        $this->assert_same(-1.25, $this->strict_call(fn() => AggregateCaster::average(-2.5, 2)));
    }

    // §5.4: the AVG of an exact column is its SUM divided by its COUNT, exactly, at the scale of the SUM plus four
    // (MySQL's rule), half up. The expected values are those of exact rational arithmetic (Python's Fraction).
    public function test_the_average_of_an_integer_column_has_four_decimals(): void
    {
        // Postgres gives the SUM of INTs as an int, MySQL as a DECIMAL string
        $this->assert_same('1.6667', AggregateCaster::average(5, 3));
        $this->assert_same('1.6667', AggregateCaster::average('5', 3));
        $this->assert_same('1.6667', AggregateCaster::average('5', '3'));
        $this->assert_same('1.5000', AggregateCaster::average(3, 2));
        $this->assert_same('-1.6667', AggregateCaster::average('-5', 3));
        $this->assert_same('0.0000', AggregateCaster::average(0, 3));
        $this->assert_same('0.0000', AggregateCaster::average('0', 3));
        // half way: away from zero, on both signs
        $this->assert_same('0.0313', AggregateCaster::average(1, 32));
        $this->assert_same('-0.0313', AggregateCaster::average(-1, 32));
        // beyond the 53 bits of a float and the 63 of an int
        $this->assert_same('4611686018427387904.0000', AggregateCaster::average('9223372036854775808', 2));
        $this->assert_same('9223372036854775807.0000', AggregateCaster::average('18446744073709551614', 2));
        $this->assert_same('9223372036854775807.6667', AggregateCaster::average('27670116110564327423', 3));
    }

    // The scale is that of the SUM as the driver wrote it, trailing zeros included: ExactDecimal::parse() drops them
    // ('2.50' has the fraction '5'), which would give 5 decimals where MySQL's AVG gives 6
    public function test_the_average_of_a_decimal_column_keeps_the_scale_of_the_sum_plus_four(): void
    {
        $this->assert_same('617283945061.730000', AggregateCaster::average('1234567890123.46', 2));
        $this->assert_same('2.500000', AggregateCaster::average('2.50', 1));
        $this->assert_same('0.000000', AggregateCaster::average('0.00', 2));
        $this->assert_same('-0.010000', AggregateCaster::average('-0.01', 1));
        $this->assert_same('-0.003333', AggregateCaster::average('-0.01', 3));
        // a SUM with no fraction digits: four
        $this->assert_same('33.3333', AggregateCaster::average('100', 3));
        // 18 integer digits, 2 decimals: a float keeps 15 to 17 of them
        $this->assert_same('999999999999999999.990000', AggregateCaster::average('2999999999999999999.97', 3));
    }

    // Postgres's numeric has up to 16383 fraction digits: the average of such a SUM has 16387, the most ExactDecimal allows
    public function test_the_average_of_the_widest_numeric_sum_is_computed(): void
    {
        $this->assert_same('1.' . str_repeat('0', 16387), AggregateCaster::average('3.' . str_repeat('0', 16383), 3));
        // 3.00…09 / 3 is 1.00…03 exactly
        $this->assert_same('1.' . str_repeat('0', 16382) . '30000', AggregateCaster::average('3.' . str_repeat('0', 16382) . '9', 3));
    }

    // A SUM that is NULL (no row, or only NULLs) has no average; neither has a COUNT of 0 (whatever the SUM)
    public function test_the_average_of_no_row_is_null(): void
    {
        $this->assert_null(AggregateCaster::average(null, 0));
        $this->assert_null(AggregateCaster::average(null, '0'));
        $this->assert_null(AggregateCaster::average('0', 0));
        $this->assert_null(AggregateCaster::average('0', '0'));
        $this->assert_null(AggregateCaster::average(0, 0));
        // a SUM and a COUNT that no database gives together: no division by a count that is not there, nor of a missing SUM
        $this->assert_null(AggregateCaster::average(null, 3));
        $this->assert_null(AggregateCaster::average('5', -1));
    }

    // The scale is read as ExactDecimal reads the number, which skips the whitespace around it
    public function test_the_scale_of_a_sum_does_not_count_the_space_around_it(): void
    {
        $this->assert_same('2.500000', AggregateCaster::average(' 2.50 ', 1));
        $this->assert_same('2.50000', AggregateCaster::average("\t2.5\n", 1));
    }

    // A SUM that the driver gives as an int is divided exactly, as a string is: Postgres gives the SUM of INTs as an int, and
    // a wrapped query's select can name a DECIMAL column by an expression of ints. Only a float SUM has no exact quotient
    // (SQLite stores a REAL in an INTEGER column), and is divided as a float.
    public function test_the_average_of_a_sum_the_driver_gives_as_an_int_is_exact_and_as_a_float_is_a_float(): void
    {
        $this->assert_same('6.0000', AggregateCaster::average(12, 2));
        $this->assert_same('0.0000', AggregateCaster::average(0, 2));
        $this->assert_same('9007199254740993.0000', AggregateCaster::average(9007199254740993, 1));
        // 9007199254740993 / 2, which no float holds
        $this->assert_same('4503599627370496.5000', AggregateCaster::average(9007199254740993, 2));
        $this->assert_same('-4611686018427387904.0000', AggregateCaster::average(PHP_INT_MIN, 2));

        $this->assert_same(617283945061.73, AggregateCaster::average(1234567890123.46, 2));
        $this->assert_same(1.75, AggregateCaster::average(3.5, 2));
        $this->assert_same(-1.25, AggregateCaster::average(-2.5, 2));
    }
}
