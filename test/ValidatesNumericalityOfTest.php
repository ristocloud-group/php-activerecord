<?php

class BookNumericality extends ActiveRecord\Model
{
    public static $table_name = 'books';

    public static $validates_numericality_of = [
        ['name'],
    ];

    // a non-column attribute holding a non-scalar value
    public function get_list_value()
    {
        return ['1'];
    }
}

class BigIdNumericality extends ActiveRecord\Model
{
    public static $table_name = 'big_ids';

    public static $validates_numericality_of = [
        ['id', 'less_than' => PHP_INT_MAX],
    ];
}

// A non-column attribute handing the validator a raw PHP value: a column would
// cast it (numeric_test is VARCHAR, so an int or a float would arrive as a string).
class RawNumericality extends ActiveRecord\Model
{
    public static $table_name = 'books';

    public static mixed $raw = null;

    public static $validates_numericality_of = [];

    public function get_raw_value()
    {
        return static::$raw;
    }
}

class ValidatesNumericalityOfTest extends DatabaseTest
{
    public static $NULL = [null];
    public static $BLANK = ["", " ", " \t \r \n"];
    public static $FLOAT_STRINGS = ['0.0','+0.0','-0.0','10.0','10.5','-10.5','-0.0001','-090.1'];
    public static $INTEGER_STRINGS = ['0', '+0', '-0', '10', '+10', '-10', '0090', '-090'];
    public static $FLOATS = [0.0, 10.0, 10.5, -10.5, -0.0001];
    public static $INTEGERS = [0, 10, -10];
    public static $JUNK = ["not a number", "42 not a number", "00-1", "--3", "+-3", "+3-1", "-+019.0", "12.12.13.12", "123\nnot a number"];

    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);
        BookNumericality::$validates_numericality_of = [
            ['numeric_test'],
        ];
    }

    private function assert_validity($value, $boolean, $msg = null)
    {
        $book = new BookNumericality();
        $book->numeric_test = $value;

        if ($boolean == 'valid') {
            $this->assert_true($book->save());
            $this->assert_false($book->errors->is_invalid('numeric_test'));
        } else {
            $this->assert_false($book->save());
            $this->assert_true($book->errors->is_invalid('numeric_test'));

            if (!is_null($msg)) {
                $this->assert_same($msg, $book->errors->on('numeric_test'));
            }
        }
    }

    private function assert_invalid($values, $msg = null)
    {
        foreach ($values as $value) {
            $this->assert_validity($value, 'invalid', $msg);
        }
    }

    private function assert_valid($values, $msg = null)
    {
        foreach ($values as $value) {
            $this->assert_validity($value, 'valid', $msg);
        }
    }

    public function test_numericality()
    {
        //$this->assert_invalid(array("0xdeadbeef"));

        $this->assert_valid(array_merge(self::$FLOATS, self::$INTEGERS));
        $this->assert_invalid(array_merge(self::$NULL, self::$BLANK, self::$JUNK));
    }

    public function test_not_anumber()
    {
        $this->assert_invalid(['blah'], 'is not a number');
    }

    public function test_invalid_null()
    {
        $this->assert_invalid([null]);
    }

    public function test_invalid_blank()
    {
        $this->assert_invalid([' ', '  '], 'is not a number');
    }

    public function test_invalid_whitespace()
    {
        $this->assert_invalid(['']);
    }

    public function test_valid_null()
    {
        BookNumericality::$validates_numericality_of[0]['allow_null'] = true;
        $this->assert_valid([null]);
    }

    public function test_allow_blank_skips_empty_string()
    {
        // numeric_test is a string column, so '' reaches the validator as-is (#50)
        BookNumericality::$validates_numericality_of[0]['allow_blank'] = true;
        $this->assert_valid(['']);
    }

    public function test_allow_blank_with_allow_null_skips_empty_string()
    {
        BookNumericality::$validates_numericality_of[0]['allow_blank'] = true;
        BookNumericality::$validates_numericality_of[0]['allow_null'] = true;
        $this->assert_valid(['', null]);
    }

    public function test_allow_blank_alone_skips_null()
    {
        // null is blank, as for the other validators that honour allow_blank
        BookNumericality::$validates_numericality_of[0]['allow_blank'] = true;
        $this->assert_valid([null]);
    }

    public function test_allow_blank_still_rejects_non_numeric_values()
    {
        BookNumericality::$validates_numericality_of[0]['allow_blank'] = true;
        $this->assert_invalid(['blah', '42 not a number'], 'is not a number');
    }

    public function test_allow_blank_still_applies_other_options()
    {
        BookNumericality::$validates_numericality_of[0]['allow_blank'] = true;
        BookNumericality::$validates_numericality_of[0]['greater_than'] = 5;
        $this->assert_valid(['', '6']);
        $this->assert_invalid(['5'], 'must be greater than 5');
    }

    public function test_empty_string_without_allow_blank_stays_invalid()
    {
        BookNumericality::$validates_numericality_of[0]['allow_null'] = true;
        $this->assert_invalid([''], 'is not a number');
    }

    public function test_non_scalar_value_without_allow_blank_stays_not_a_number()
    {
        BookNumericality::$validates_numericality_of = [['list_value']];
        $book = new BookNumericality();
        $this->assert_false($book->is_valid());
        $this->assert_same('is not a number', $book->errors->on('list_value'));
    }

    public function test_only_integer()
    {
        BookNumericality::$validates_numericality_of[0]['only_integer'] = true;

        $this->assert_valid([1, '1']);
        $this->assert_invalid([1.5, '1.5']);
    }

    public function test_only_integer_matching_does_not_ignore_other_options()
    {
        BookNumericality::$validates_numericality_of[0]['only_integer'] = true;
        BookNumericality::$validates_numericality_of[0]['greater_than'] = 0;

        $this->assert_invalid([-1,'-1']);
    }

    public function test_greater_than()
    {
        BookNumericality::$validates_numericality_of[0]['greater_than'] = 5;

        $this->assert_valid([6, '7']);
        $this->assert_invalid([5, '5'], 'must be greater than 5');
    }

    public function test_greater_than_or_equal_to()
    {
        BookNumericality::$validates_numericality_of[0]['greater_than_or_equal_to'] = 5;

        $this->assert_valid([5, 5.1, '5.1']);
        $this->assert_invalid([-50, 4.9, '4.9','-5.1']);
    }

    public function test_less_than()
    {
        BookNumericality::$validates_numericality_of[0]['less_than'] = 5;

        $this->assert_valid([4.9, -1, 0, '-5']);
        $this->assert_invalid([5, '5'], 'must be less than 5');
    }

    public function test_less_than_or_equal_to()
    {
        BookNumericality::$validates_numericality_of[0]['less_than_or_equal_to'] = 5;

        $this->assert_valid([5, -1, 0, 4.9, '-5']);
        $this->assert_invalid(['8', 5.1], 'must be less than or equal to 5');
    }

    public function test_greater_than_less_than_and_even()
    {
        BookNumericality::$validates_numericality_of[0] = ['numeric_test', 'greater_than' => 1, 'less_than' => 4, 'even' => true];

        $this->assert_valid([2]);
        $this->assert_invalid([1,3,4]);
    }

    public function test_odd()
    {
        BookNumericality::$validates_numericality_of[0] = ['numeric_test', 'odd' => true];

        $this->assert_valid([1, 3]);
        $this->assert_invalid([2, 4], 'must be odd');
    }

    public function test_odd_truncates_non_integer_values()
    {
        // Rails semantics (value.to_i.odd?): a non-integer is truncated toward
        // zero before the parity check, without PHP's implicit float-to-int
        // deprecation (#59).
        BookNumericality::$validates_numericality_of[0] = ['numeric_test', 'odd' => true];

        $this->assert_valid(['3.5', '-3.5']);
        $this->assert_invalid(['4.5'], 'must be odd');
    }

    public function test_even_truncates_non_integer_values()
    {
        BookNumericality::$validates_numericality_of[0] = ['numeric_test', 'even' => true];

        $this->assert_valid(['4.5']);
        $this->assert_invalid(['3.5'], 'must be even');
    }

    public function test_odd_truncates_non_integer_decimal_column_value()
    {
        // A DECIMAL column hands the validator a native float.
        BookNumericality::$validates_numericality_of[0] = ['special', 'odd' => true];

        $book = new BookNumericality(['special' => 3.5]);
        $this->assert_same(3.5, $book->special);
        $this->assert_true($book->is_valid());
    }

    /**
     * Validates numeric_test = $value with the given options on a new record and
     * returns null when it is valid, else the error message. is_valid() rather than
     * save(): numeric_test is VARCHAR(10) and the values here are longer.
     *
     * @param array<string, mixed> $options
     */
    private function numericality_error(mixed $value, array $options): ?string
    {
        BookNumericality::$validates_numericality_of = [array_merge(['numeric_test'], $options)];
        $book = new BookNumericality();
        $book->numeric_test = $value;

        if ($book->is_valid()) {
            return null;
        }

        return $book->errors->on('numeric_test');
    }

    /**
     * As numericality_error(), with $value reaching the validator as is (a native int or float).
     *
     * @param array<string, mixed> $options
     */
    private function raw_numericality_error(int|float $value, array $options): ?string
    {
        RawNumericality::$raw = $value;
        RawNumericality::$validates_numericality_of = [array_merge(['raw_value'], $options)];
        $model = new RawNumericality();

        if ($model->is_valid()) {
            return null;
        }

        return $model->errors->on('raw_value');
    }

    public function test_compares_integers_beyond_2_pow_53_exactly()
    {
        // (float) rounds both sides to the same double: 9223372036854775806 < PHP_INT_MAX
        // was rejected, 9007199254740993 == 9007199254740992 accepted (#49)
        $this->assert_null($this->numericality_error('9223372036854775806', ['less_than' => PHP_INT_MAX]));
        $this->assert_same('must be less than 9223372036854775807', $this->numericality_error('9223372036854775807', ['less_than' => PHP_INT_MAX]));
        $this->assert_same('must be equal to 9007199254740992', $this->numericality_error('9007199254740993', ['equal_to' => 9007199254740992]));
        $this->assert_null($this->numericality_error('9007199254740993', ['greater_than' => '9007199254740992']));
        $this->assert_same('must be greater than or equal to 9007199254740993', $this->numericality_error('9007199254740992', ['greater_than_or_equal_to' => '9007199254740993']));
    }

    public function test_compares_native_int_values_beyond_2_pow_53_exactly()
    {
        $this->assert_null($this->raw_numericality_error(9007199254740993, ['greater_than' => 9007199254740992]));
        $this->assert_same('must be equal to 9007199254740992', $this->raw_numericality_error(9007199254740993, ['equal_to' => '9007199254740992']));
        $this->assert_null($this->raw_numericality_error(9223372036854775806, ['less_than' => PHP_INT_MAX]));
        $this->assert_null($this->raw_numericality_error(PHP_INT_MIN, ['less_than' => PHP_INT_MIN + 1]));
        $this->assert_same('must be greater than -9223372036854775807', $this->raw_numericality_error(PHP_INT_MIN, ['greater_than' => PHP_INT_MIN + 1]));
        $this->assert_null($this->raw_numericality_error(9007199254740993, ['only_integer' => true, 'greater_than' => '9007199254740992']));
        $this->assert_null($this->raw_numericality_error(9007199254740993, ['odd' => true]));
        $this->assert_same('must be even', $this->raw_numericality_error(9007199254740993, ['even' => true]));
        $this->assert_null($this->raw_numericality_error(PHP_INT_MAX, ['odd' => true]));
        // int value vs integer-string bound
        $this->assert_null($this->raw_numericality_error(10, ['equal_to' => '10']));
    }

    public function test_compares_unsigned_bigint_strings_beyond_php_int_max_exactly()
    {
        // a BIGINT UNSIGNED value above PHP_INT_MAX reaches the validator as a string (#44)
        $this->assert_null($this->numericality_error('18446744073709551615', ['less_than' => '18446744073709551616']));
        $this->assert_same('must be less than or equal to 18446744073709551614', $this->numericality_error('18446744073709551615', ['less_than_or_equal_to' => '18446744073709551614']));
        $this->assert_null($this->numericality_error('18446744073709551615', ['equal_to' => '18446744073709551615', 'greater_than' => PHP_INT_MAX]));
        $this->assert_same('must be less than 9223372036854775807', $this->numericality_error('18446744073709551615', ['less_than' => PHP_INT_MAX]));
    }

    public function test_compares_a_bigint_column_value_exactly()
    {
        $record = new BigIdNumericality(['id' => '9223372036854775806']);
        $this->assert_same(9223372036854775806, $record->id);
        $this->assert_true($record->is_valid());

        $record->id = PHP_INT_MAX;
        $this->assert_false($record->is_valid());
        $this->assert_same('must be less than 9223372036854775807', $record->errors->on('id'));
    }

    public function test_only_integer_compares_big_bounds_exactly()
    {
        $this->assert_same('must be equal to 9007199254740992', $this->numericality_error('9007199254740993', ['only_integer' => true, 'equal_to' => '9007199254740992']));
        $this->assert_null($this->numericality_error('9223372036854775806', ['only_integer' => true, 'less_than' => PHP_INT_MAX]));
        $this->assert_null($this->numericality_error('-18446744073709551616', ['only_integer' => true, 'less_than' => '-18446744073709551615']));
    }

    public function test_odd_and_even_of_big_integers_are_exact()
    {
        foreach (['9007199254740993', '9223372036854775807', '18446744073709551615', '-9007199254740993', '9007199254740993.5'] as $odd) {
            $this->assert_null($this->numericality_error($odd, ['odd' => true]), var_export($odd, true));
            $this->assert_same('must be even', $this->numericality_error($odd, ['even' => true]), var_export($odd, true));
        }
        foreach (['9007199254740992', '18446744073709551614', '-18446744073709551616'] as $even) {
            $this->assert_null($this->numericality_error($even, ['even' => true]), $even);
            $this->assert_same('must be odd', $this->numericality_error($even, ['odd' => true]), $even);
        }
        $this->assert_null($this->numericality_error('18446744073709551615', ['only_integer' => true, 'odd' => true]));
        $this->assert_null($this->numericality_error('18446744073709551614', ['only_integer' => true, 'even' => true]));
    }

    public function test_exact_comparison_of_mixed_and_decimal_operands()
    {
        // integer-string value vs int bound
        $this->assert_null($this->numericality_error('10', ['equal_to' => 10]));
        // leading zeros, sign, surrounding whitespace
        $this->assert_null($this->numericality_error('0090', ['equal_to' => 90]));
        $this->assert_null($this->numericality_error('+10', ['equal_to' => '10']));
        $this->assert_null($this->numericality_error(' 5', ['equal_to' => '5 ']));
        $this->assert_null($this->numericality_error('-0', ['equal_to' => 0]));
        $this->assert_null($this->numericality_error('-0.0', ['greater_than_or_equal_to' => '0', 'less_than_or_equal_to' => '-0']));
        $this->assert_null($this->numericality_error('5.0', ['equal_to' => 5]));
        $this->assert_null($this->numericality_error('5.10', ['greater_than' => '5.09']));
        $this->assert_same('must be less than 5.09', $this->numericality_error('5.10', ['less_than' => '5.09']));
        $this->assert_null($this->numericality_error('.5', ['equal_to' => '0.50']));
        $this->assert_null($this->numericality_error('5.', ['equal_to' => '5']));
        $this->assert_null($this->numericality_error('-5.5', ['less_than' => '-5.49', 'greater_than' => '-5.51']));
        // a decimal string beyond the double's precision is compared digit by digit
        $this->assert_null($this->numericality_error('12345678901234567890.15', ['less_than' => '12345678901234567890.16']));
        $this->assert_same('must be greater than -0.1', $this->numericality_error('-0.10000000000000001', ['greater_than' => '-0.1']));
    }

    public function test_integer_bound_message_keeps_every_digit()
    {
        $this->assert_same('must be less than 100000000000000', $this->numericality_error('100000000000000', ['less_than' => 100000000000000]));
        $this->assert_same('must be greater than 90', $this->numericality_error('5', ['greater_than' => '0090']));
        $this->assert_same('must be greater than 10', $this->numericality_error('5', ['greater_than' => '+10']));
        $this->assert_same('must be greater than 0', $this->numericality_error('-5', ['greater_than' => '-0']));
        $this->assert_same('must be greater than -12', $this->numericality_error('-15', ['greater_than' => '-012']));
        $this->assert_same('must be greater than 0', $this->numericality_error('-5', ['greater_than' => '-0.0']));
        $this->assert_same('must be less than 123456789012345', $this->numericality_error('123456789012346', ['less_than' => '123456789012345.0']));
    }

    public function test_float_operands_keep_the_float_comparison()
    {
        // pins: a native float or an exponent string is compared as a float, as before
        $this->assert_null($this->numericality_error('6', ['less_than' => '1e1']));
        $this->assert_same('must be less than 10', $this->numericality_error('20', ['less_than' => '1e1']));
        $this->assert_same('must be less than 5', $this->numericality_error('6', ['less_than' => 5.0]));
        $this->assert_null($this->numericality_error('0.1', ['equal_to' => 0.1]));
        $this->assert_null($this->numericality_error('9007199254740993', ['equal_to' => 9007199254740992.0]));
        $this->assert_null($this->numericality_error('1e1', ['equal_to' => '10']));
    }

    public function test_native_float_values_keep_the_float_comparison()
    {
        // pins: a native float value is compared (and its parity taken) as a float, as before
        $this->assert_null($this->raw_numericality_error(4.9, ['less_than' => '5']));
        $this->assert_same('must be greater than or equal to 5', $this->raw_numericality_error(4.9, ['greater_than_or_equal_to' => 5]));
        $this->assert_null($this->raw_numericality_error(0.1, ['equal_to' => '0.1']));
        $this->assert_null($this->raw_numericality_error(9007199254740993.0, ['equal_to' => '9007199254740993']));
        $this->assert_null($this->raw_numericality_error(9007199254740993.0, ['even' => true]));
        $this->assert_same('must be odd', $this->raw_numericality_error(9007199254740993.0, ['odd' => true]));
        $this->assert_null($this->raw_numericality_error(3.5, ['odd' => true]));
    }

    public function test_long_decimal_strings_are_compared_exactly()
    {
        // declared consequences of D1: a decimal string is compared digit by digit even
        // within 2^53, where (float) used to round both sides to the same double
        // (a non-integer bound is still rendered as (string) (float), as before: D2)
        $this->assert_same('must be equal to 9', $this->numericality_error('9.000000000000001', ['equal_to' => '9.000000000000002']));
        $this->assert_null($this->numericality_error('0.' . str_repeat('0', 330) . '1', ['greater_than' => 0]));
    }

    public function test_non_integer_bound_message_is_unchanged()
    {
        $this->assert_same('must be less than 5.5', $this->numericality_error('6', ['less_than' => '5.50']));
        $this->assert_same('must be less than 5', $this->numericality_error('6', ['less_than' => '5.0']));
        $this->assert_same('must be less than 1.5E+20', $this->numericality_error('1.6e20', ['less_than' => 1.5e20]));
    }

    public function test_non_numeric_bound_still_throws()
    {
        $this->expect_exception(ActiveRecord\ValidationsArgumentError::class);
        $this->expect_exception_message('less_than must be a number');
        $this->numericality_error('5', ['less_than' => 'five']);
    }

    public function test_custom_message()
    {
        BookNumericality::$validates_numericality_of = [
            ['numeric_test', 'message' => 'Hello'],
        ];
        $book = new BookNumericality(['numeric_test' => 'NaN']);
        $book->is_valid();
        $this->assert_equals(['Numeric test Hello'], $book->errors->full_messages());
    }
};

array_merge(ValidatesNumericalityOfTest::$INTEGERS, ValidatesNumericalityOfTest::$INTEGER_STRINGS);
array_merge(ValidatesNumericalityOfTest::$FLOATS, ValidatesNumericalityOfTest::$FLOAT_STRINGS);
