<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\ExactDecimal;

/**
 * ActiveRecord\ExactDecimal: the one exact-decimal helper of Validations (#175) and of the engine's aggregates,
 * in pure PHP (no bcmath, no gmp, no float). It needs no database.
 *
 * The expected values of the divisions that have no closed form were computed apart from this code, with exact
 * rational arithmetic on Python's integers (a different algorithm: it scales the dividend and compares twice the
 * remainder with the divisor, where ExactDecimal divides digit by digit and decides on the first dropped digit).
 */
class ExactDecimalTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    /**
     * Asserts every division of $cases: [expected, dividend, divisor, scale].
     *
     * @param list<array{string, int|string, int, int}> $cases
     */
    private function assert_divisions(array $cases): void
    {
        foreach ($cases as [$expected, $dividend, $divisor, $scale]) {
            $this->assert_same($expected, ExactDecimal::divide($dividend, $divisor, $scale), sprintf('divide(%s, %d, %d)', var_export($dividend, true), $divisor, $scale));
        }
    }

    /**
     * Asserts that $call throws an ActiveRecordException whose message holds every one of $mentions.
     *
     * @param list<string> $mentions
     */
    private function assert_refused(callable $call, array $mentions, string $case): void
    {
        try {
            $call();
        } catch (ActiveRecordException $e) {
            foreach ($mentions as $mention) {
                $this->assert_true(str_contains($e->getMessage(), $mention), "$case: '$mention' is not in: {$e->getMessage()}");
            }

            return;
        }

        $this->fail("$case: nothing was thrown");
    }

    public function test_divide_rounds_half_up_at_the_scale(): void
    {
        $this->assert_same('1.5000', ExactDecimal::divide('3', 2, 4));
        $this->assert_same('1.6667', ExactDecimal::divide('5', 3, 4));
        $this->assert_same('-1.6667', ExactDecimal::divide('-5', 3, 4));
        $this->assert_same('617283945061.730000', ExactDecimal::divide('1234567890123.46', 2, 6));
        $this->assert_same('4611686018427387850.0000', ExactDecimal::divide('9223372036854775700', 2, 4));
        $this->assert_same('9223372036854775807.5000', ExactDecimal::divide('18446744073709551615', 2, 4));
        $this->assert_same('0.0000', ExactDecimal::divide('0', 5, 4));
        $this->assert_same('1', ExactDecimal::divide('2', 3, 0));
    }

    public function test_to_int_or_string_keeps_64_bit_exact(): void
    {
        $this->assert_same(PHP_INT_MAX, ExactDecimal::to_int_or_string('9223372036854775807'));
        $this->assert_same('9223372036854775808', ExactDecimal::to_int_or_string('9223372036854775808'));
        $this->assert_same(PHP_INT_MIN, ExactDecimal::to_int_or_string('-9223372036854775808'));
        $this->assert_same(90, ExactDecimal::to_int_or_string('0090'));
    }

    public function test_compare_matches_the_175_semantics(): void
    {
        $this->assert_same(-1, ExactDecimal::compare(ExactDecimal::parse('9007199254740992'), ExactDecimal::parse('9007199254740993')));
        $this->assert_same(0, ExactDecimal::compare(ExactDecimal::parse('+10.50'), ExactDecimal::parse('10.5')));
        $this->assert_null(ExactDecimal::parse('1e1'));
    }

    public function test_divide_rounds_a_tie_away_from_zero(): void
    {
        // each quotient is exactly half-way at the scale: the magnitude goes up, a negative one too
        $this->assert_divisions([
            ['0.13', '1', 8, 2],
            ['-0.13', '-1', 8, 2],
            ['1', '1', 2, 0],
            ['-1', '-1', 2, 0],
            ['2', '3', 2, 0],
            ['-2', '-3', 2, 0],
            ['3', '2.5', 1, 0],
            ['-3', '-2.5', 1, 0],
            ['1', '0.5', 1, 0],
            ['-1', '-0.5', 1, 0],
            ['1', '5', 10, 0],
            ['0.03', '0.05', 2, 2],
            ['0.025', '0.05', 2, 3],
            ['-0.0001', '-0.00005', 1, 4],
        ]);
    }

    public function test_divide_rounds_on_the_digits_it_drops_never_on_a_float(): void
    {
        // a quotient just under a tie goes down, one on it goes up, whatever follows the digit that decides. 2.675
        // and 1.005 are ties here, as their digits say, where the doubles of those numbers are 2.67499999999999982...
        // and 1.00499999999999989..., below the tie
        $this->assert_divisions([
            ['2.68', '2.675', 1, 2],
            ['1.01', '1.005', 1, 2],
            ['1.00', '1.004999', 1, 2],
            ['1.00', '1.00499999999999999999999999', 1, 2],
            ['1.01', '1.00500000000000000000000001', 1, 2],
            ['0.00', '0.004999999999999999999999999999', 1, 2],
            ['0.00', '-0.004999999', 1, 2],
        ]);
    }

    public function test_divide_carries_into_the_integer_part(): void
    {
        // the dropped digits round the last kept digit up through a run of nines
        $this->assert_divisions([
            ['1.0000', '0.99995', 1, 4],
            ['10.0000', '9.99995', 1, 4],
            ['100000000000000000000.0000', '99999999999999999999.99995', 1, 4],
            ['1.000', '0.9999', 1, 3],
            ['1.00000', '0.999999', 1, 5],
            ['-1.0000', '-0.99995', 1, 4],
            ['10', '9.5', 1, 0],
            ['100', '99.5', 1, 0],
            ['1000', '999.5', 1, 0],
            ['0.9999', '0.99989999', 1, 4],
        ]);
    }

    public function test_divide_never_returns_a_negative_zero(): void
    {
        // a negative quotient that rounds to zero is '0.0000', never '-0.0000'; one that does not is signed
        $this->assert_divisions([
            ['0.0000', '-0.00001', 1, 4],
            ['0.0000', '-0.00004', 1, 4],
            ['-0.0001', '-0.00005', 1, 4],
            ['0', '-1', 3, 0],
            ['0', '-0.4', 1, 0],
            ['0.00', '-0', 5, 2],
            ['0.00', '-0.00', 5, 2],
            ['0.0000', '-1', 1000000000000000000, 4],
            ['0.0000', '-1', PHP_INT_MAX, 4],
        ]);
    }

    public function test_divide_by_the_largest_divisors(): void
    {
        // the digit step multiplies the remainder by 10: a divisor above PHP_INT_MAX / 10 would overflow it
        // into a float, so 922337203685477580 (the last native one) and 922337203685477581 (the first
        // overflow-safe one) are both pinned, with PHP_INT_MAX itself
        $this->assert_divisions([
            ['1.0000', (string) PHP_INT_MAX, PHP_INT_MAX, 4],
            ['0.000000000000000000108420217249', '1', PHP_INT_MAX, 30],
            ['2.0000', '18446744073709551615', PHP_INT_MAX, 4],
            ['2.00000000000000000011', '18446744073709551615', PHP_INT_MAX, 20],
            ['0.99999999999999999989', '9223372036854775806', PHP_INT_MAX, 20],
            ['1.000000000000000000', '9223372036854775806', PHP_INT_MAX, 18],
            ['1.00000000000000000011', '9223372036854775807', PHP_INT_MAX - 1, 20],
            ['0.000', '7', PHP_INT_MAX, 3],
            ['-1.0000', PHP_INT_MIN, PHP_INT_MAX, 4],
            ['10.000000000000', '9223372036854775807', 922337203685477580, 12],
            ['10.000000000000', '9223372036854775807', 922337203685477581, 12],
            // the edge itself: with the remainder at its largest (divisor - 1), a step makes 10 * remainder + digit.
            // Below the switch that is 9223372036854775799 at most, one divisor above it 9223372036854775808 and
            // 9223372036854775809 (beyond PHP_INT_MAX), as these dividends reach
            ['0.9999999999999999998915798', '922337203685477579.9', 922337203685477580, 25],
            ['0.9999999999999999997831596', '922337203685477580.8', 922337203685477581, 25],
            ['0.9999999999999999998915798', '922337203685477580.9', 922337203685477581, 25],
            ['0.9999999999999999999891580', '9223372036854775806.9', PHP_INT_MAX, 25],
            ['0.9999999999999999989157978', '922337203685477580', 922337203685477581, 25],
            ['1.00000', '922337203685477581', 922337203685477581, 5],
            ['100.000000', '92233720368547758070', 922337203685477581, 6],
            ['108420217248550443494784828.152777', str_repeat('9', 44), 922337203685477580, 6],
            ['108420217248550443377235393.070548', str_repeat('9', 44), 922337203685477581, 6],
            ['10842021724855044341250022.359522', str_repeat('9', 44), PHP_INT_MAX, 6],
            ['21684043449710088680149056.017399', str_repeat('9', 44), 2 ** 62, 6],
            ['1338521188552697383678617745052.6150029875', '12345678901234567890123456789012345678901234567890', PHP_INT_MAX, 10],
        ]);
    }

    public function test_divide_by_a_wide_divisor_is_exact_when_a_sum_meets_it(): void
    {
        // 2 ** 62 and PHP_INT_MAX are beyond the switch, and these dividends are halves and multiples of them:
        // a sum of the digit step then reaches the divisor exactly, and must wrap there
        $this->assert_divisions([
            ['1', '2305843009213693952', 2 ** 62, 0],
            ['0', '2305843009213693951', 2 ** 62, 0],
            ['1', '2305843009213693953', 2 ** 62, 0],
            ['2', '6917529027641081856', 2 ** 62, 0],
            ['1', '6917529027641081855', 2 ** 62, 0],
            ['1.0000', '4611686018427387904', 2 ** 62, 4],
            ['3.0000', '13835058055282163712', 2 ** 62, 4],
            ['10', '46116860184273879040', 2 ** 62, 0],
            ['1', '9223372036854775807', PHP_INT_MAX, 0],
            ['3.0000', '27670116110564327421', PHP_INT_MAX, 4],
            ['2.00', '18446744073709551614', PHP_INT_MAX, 2],
        ]);
    }

    public function test_divide_long_dividends(): void
    {
        // dividends of 40 and more digits, which no int nor float could hold
        $this->assert_divisions([
            ['17636684144620811271604938270017636684144620811.1429', '123456789012345678901234567890123456789012345678', 7, 4],
            ['411522630041152263004115226300411522630.041152', '1234567890123456789012345678901234567890.123456789', 3, 6],
            ['1111111111111111111111111111111111111111.00', str_repeat('9', 40), 9, 2],
            ['33333333333333333333333333333333333333333333.333', '1' . str_repeat('0', 44), 3, 3],
            ['0.3333', '0.' . str_repeat('3', 50), 1, 4],
            ['0.1428571428571428571428571428571428571429', '1', 7, 40],
        ]);
    }

    public function test_divide_takes_an_int_and_gives_exactly_the_scale(): void
    {
        $this->assert_divisions([
            ['3.5', 7, 2, 1],
            ['0.00', 0, 1, 2],
            ['-4611686018427387904', PHP_INT_MIN, 2, 0],
            ['9223372036854775807', PHP_INT_MAX, 1, 0],
            ['9223372036854775807.0', PHP_INT_MAX, 1, 1],
            ['5', 10, 2, 0],
            ['5.00000', 10, 2, 5],
            ['0', 1, 3, 0],
        ]);
    }

    public function test_divide_reads_the_dividend_as_parse_does(): void
    {
        // a sign, surrounding whitespace, leading zeros and a missing integer or fraction part
        $this->assert_divisions([
            ['3.75', '+7.50', 2, 2],
            ['3.75', " 7.5\n", 2, 2],
            ['3.5', '0007', 2, 1],
            ['0.25', '.5', 2, 2],
            ['2.5', '5.', 2, 1],
            ['-3.75', '-7.5', 2, 2],
        ]);
    }

    public function test_divide_refuses_a_divisor_that_is_not_positive(): void
    {
        foreach ([0, -1, -3, PHP_INT_MIN] as $divisor) {
            $this->assert_refused(fn() => ExactDecimal::divide('5', $divisor, 2), ['ExactDecimal::divide()', 'divisor', (string) $divisor], "divisor $divisor");
        }
    }

    public function test_divide_refuses_a_negative_scale(): void
    {
        foreach ([-1, -4, PHP_INT_MIN] as $scale) {
            $this->assert_refused(fn() => ExactDecimal::divide('5', 2, $scale), ['ExactDecimal::divide()', 'scale', (string) $scale], "scale $scale");
        }
    }

    // The scale is capped at 16387: the 16383 digits after the point of Postgres's widest numeric, and the 4 that the average
    // of such a number adds. A scale near PHP_INT_MAX would end in PHP's fatal "Allowed memory size exhausted", which
    // nothing can catch, so it is refused before any digit is made.
    public function test_divide_takes_a_scale_up_to_the_widest_numeric_and_refuses_a_larger_one(): void
    {
        $this->assert_same('0.' . str_repeat('3', 16387), ExactDecimal::divide('1', 3, 16387));
        $this->assert_same('0.' . str_repeat('3', 16386), ExactDecimal::divide('1', 3, 16386));
        $this->assert_same(16387, ExactDecimal::MAX_SCALE);

        foreach ([16388, 16389, 100000, PHP_INT_MAX - 1, PHP_INT_MAX] as $scale) {
            $this->assert_refused(fn() => ExactDecimal::divide('1', 3, $scale), ['ExactDecimal::divide()', 'scale', 'at most 16387', (string) $scale], "scale $scale");
        }
    }

    public function test_divide_refuses_a_dividend_that_is_no_plain_decimal(): void
    {
        foreach (['', ' ', 'abc', '1e3', '1.5e-3', '0x1A', '1,5', '5 5', '1.5.2', '-', '.', '+.', '--5'] as $dividend) {
            $this->assert_refused(fn() => ExactDecimal::divide($dividend, 2, 2), ['ExactDecimal::divide()', 'dividend', var_export($dividend, true)], "dividend '$dividend'");
        }
    }

    public function test_to_int_or_string_decides_by_digits_at_the_bounds(): void
    {
        // the exact value, never (int) of a string beyond the range (it saturates) nor a float
        $this->assert_same(PHP_INT_MAX, ExactDecimal::to_int_or_string((string) PHP_INT_MAX));
        $this->assert_same(PHP_INT_MAX - 1, ExactDecimal::to_int_or_string('9223372036854775806'));
        $this->assert_same('9223372036854775808', ExactDecimal::to_int_or_string('9223372036854775808'));
        $this->assert_same('9223372036854775809', ExactDecimal::to_int_or_string('9223372036854775809'));
        $this->assert_same(PHP_INT_MIN, ExactDecimal::to_int_or_string((string) PHP_INT_MIN));
        $this->assert_same(PHP_INT_MIN + 1, ExactDecimal::to_int_or_string('-9223372036854775807'));
        $this->assert_same('-9223372036854775809', ExactDecimal::to_int_or_string('-9223372036854775809'));
        $this->assert_same('-9223372036854775810', ExactDecimal::to_int_or_string('-9223372036854775810'));
        // a BIGINT UNSIGNED maximum, and 20 and 40 digits
        $this->assert_same('18446744073709551615', ExactDecimal::to_int_or_string('18446744073709551615'));
        $this->assert_same('-18446744073709551616', ExactDecimal::to_int_or_string('-18446744073709551616'));
        $this->assert_same(str_repeat('9', 40), ExactDecimal::to_int_or_string(str_repeat('9', 40)));
        $this->assert_same('-' . str_repeat('9', 40), ExactDecimal::to_int_or_string('-' . str_repeat('9', 40)));
        // the digits of a value that fits are the shorter, or the same length and not above the bound
        $this->assert_same(999999999999999999, ExactDecimal::to_int_or_string('999999999999999999'));
        $this->assert_same(1000000000000000000, ExactDecimal::to_int_or_string('1000000000000000000'));
        $this->assert_same('9999999999999999999', ExactDecimal::to_int_or_string('9999999999999999999'));
        $this->assert_same('-9999999999999999999', ExactDecimal::to_int_or_string('-9999999999999999999'));
    }

    public function test_to_int_or_string_normalizes_zeros_signs_and_spaces(): void
    {
        // a value that fits is an int whatever its spelling
        foreach (['0', '-0', '+0', '00', '-000', '0.0', '-0.00', ' 0 '] as $zero) {
            $this->assert_same(0, ExactDecimal::to_int_or_string($zero), var_export($zero, true));
        }
        $this->assert_same(7, ExactDecimal::to_int_or_string('+7'));
        $this->assert_same(-7, ExactDecimal::to_int_or_string('-007'));
        $this->assert_same(42, ExactDecimal::to_int_or_string(" 42\n"));
        // a fraction of zeros is the same value
        $this->assert_same(5, ExactDecimal::to_int_or_string('5.00'));
        $this->assert_same(-5, ExactDecimal::to_int_or_string('-5.0'));
        $this->assert_same(PHP_INT_MAX, ExactDecimal::to_int_or_string('9223372036854775807.000'));
        $this->assert_same(PHP_INT_MIN, ExactDecimal::to_int_or_string('-9223372036854775808.0'));
        // leading zeros are decided on the value: at the bounds and beyond them
        $this->assert_same(PHP_INT_MAX, ExactDecimal::to_int_or_string('0009223372036854775807'));
        $this->assert_same(PHP_INT_MIN, ExactDecimal::to_int_or_string('-0009223372036854775808'));
        // beyond them, the digits come back normalized: no leading zeros, no plus, no whitespace
        $this->assert_same('9223372036854775808', ExactDecimal::to_int_or_string('0009223372036854775808'));
        $this->assert_same('9223372036854775808', ExactDecimal::to_int_or_string('+9223372036854775808'));
        $this->assert_same('9223372036854775808', ExactDecimal::to_int_or_string(" 9223372036854775808\t"));
        $this->assert_same('9223372036854775808', ExactDecimal::to_int_or_string('9223372036854775808.00'));
        $this->assert_same('-9223372036854775809', ExactDecimal::to_int_or_string('-0009223372036854775809'));
        $this->assert_same('1' . str_repeat('0', 30), ExactDecimal::to_int_or_string('0000' . '1' . str_repeat('0', 30)));
    }

    public function test_to_int_or_string_passes_an_int_through(): void
    {
        foreach ([0, 1, -1, 90, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1] as $int) {
            $this->assert_same($int, ExactDecimal::to_int_or_string($int));
        }
    }

    public function test_to_int_or_string_refuses_what_is_not_an_exact_integer(): void
    {
        // an integer column has no fraction and is no text: a decimal keeps its scale and does not come through here
        foreach (['5.5', '-0.5', '0.0001', '9223372036854775808.5', '', ' ', 'abc', '1e3', '0x1A', '1,5', '5 5', '--5', '.', '-'] as $value) {
            $this->assert_refused(fn() => ExactDecimal::to_int_or_string($value), ['ExactDecimal::to_int_or_string()', var_export($value, true)], "value '$value'");
        }
    }

    public function test_parse_splits_a_plain_decimal(): void
    {
        $this->assert_same([false, '7', '5'], ExactDecimal::parse('007.500'));
        $this->assert_same([true, '12', ''], ExactDecimal::parse(' -12 '));
        $this->assert_same([false, '', '5'], ExactDecimal::parse('.5'));
        $this->assert_same([false, '5', ''], ExactDecimal::parse('+5.'));
        // zero is never negative
        $this->assert_same([false, '', ''], ExactDecimal::parse('-0'));
        $this->assert_same([false, '', ''], ExactDecimal::parse('-0.00'));
        $this->assert_same([false, '', ''], ExactDecimal::parse(0));
        $this->assert_same([true, '9223372036854775808', ''], ExactDecimal::parse(PHP_INT_MIN));
        $this->assert_same([false, '9223372036854775807', ''], ExactDecimal::parse(PHP_INT_MAX));
    }

    public function test_parse_leaves_floats_and_exponents_to_the_caller(): void
    {
        // these are compared as floats, as before #175
        foreach ([1.5, 1e1, 0.0, '1e1', '1.5E3', '', ' ', '.', '-', '+.', 'abc', '0x1A', '1,5', '5 5', '1.5.2', '--5', null, true, false, []] as $value) {
            $this->assert_null(ExactDecimal::parse($value), var_export($value, true));
        }
    }

    public function test_integer_digits_gives_the_digits_of_an_integer_only(): void
    {
        $this->assert_same('-12', ExactDecimal::integer_digits(ExactDecimal::parse('-012')));
        $this->assert_same('90', ExactDecimal::integer_digits(ExactDecimal::parse('0090')));
        $this->assert_same('5', ExactDecimal::integer_digits(ExactDecimal::parse('5.00')));
        $this->assert_same('0', ExactDecimal::integer_digits(ExactDecimal::parse('-0')));
        $this->assert_same('0', ExactDecimal::integer_digits(ExactDecimal::parse('0.0')));
        $this->assert_same('18446744073709551615', ExactDecimal::integer_digits(ExactDecimal::parse('18446744073709551615')));
        $this->assert_null(ExactDecimal::integer_digits(ExactDecimal::parse('5.1')));
        $this->assert_null(ExactDecimal::integer_digits(ExactDecimal::parse('.5')));
        $this->assert_null(ExactDecimal::integer_digits(ExactDecimal::parse('1e1')));
        $this->assert_null(ExactDecimal::integer_digits(null));
    }

    public function test_compare_orders_exact_decimals(): void
    {
        $compare = fn(string $a, string $b): int => ExactDecimal::compare(ExactDecimal::parse($a), ExactDecimal::parse($b));

        $this->assert_same(-1, $compare('99', '100'));
        $this->assert_same(1, $compare('100', '99'));
        $this->assert_same(1, $compare('-99', '-100'));
        $this->assert_same(-1, $compare('-100', '-99'));
        $this->assert_same(-1, $compare('-1', '1'));
        $this->assert_same(1, $compare('1', '-1'));
        $this->assert_same(1, $compare('5.10', '5.09'));
        $this->assert_same(-1, $compare('5.09', '5.10'));
        $this->assert_same(1, $compare('1.5', '1.45'));
        $this->assert_same(0, $compare('.5', '0.50'));
        $this->assert_same(0, $compare('-0', '0.0'));
        $this->assert_same(0, $compare('0090', '90.000'));
        $this->assert_same(-1, $compare('-0.10000000000000001', '-0.1'));
        $this->assert_same(-1, $compare('18446744073709551615', '18446744073709551616'));
        $this->assert_same(1, $compare('9223372036854775807', '9223372036854775806'));
        $this->assert_same(-1, $compare('0.' . str_repeat('0', 330) . '1', '1'));
        $this->assert_same(1, $compare('0.' . str_repeat('0', 330) . '1', '0'));
    }
}
