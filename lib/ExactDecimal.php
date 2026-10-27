<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Exact decimal arithmetic in pure PHP, with no float and no extension (no bcmath, no gmp: #175). A decimal is read
 * from an int or a plain decimal string into its digits, and compared, normalized or divided on them, so a number of
 * any size keeps every digit, where a float keeps 53 bits and an int 63.
 *
 * It serves Validations, which compares numbers exactly (#175), and the aggregates of Query: the int-or-digits rule of
 * a MIN, a MAX or a SUM of an integer column, and the exact division of an AVG.
 *
 * @internal Serves Validations and Query; not a supported API.
 * @package ActiveRecord
 */
final class ExactDecimal
{
    /**
     * Splits an int or a numeric string in plain decimal notation (optional
     * surrounding whitespace and sign, digits with an optional fraction) into
     * [negative, integer digits, fraction digits], without leading zeros in the
     * integer digits nor trailing zeros in the fraction ('' for none; zero is
     * never negative). Returns null for anything else — a float, an exponent
     * string — which is then compared as a float, as before (#49).
     *
     * @return array{bool, string, string}|null
     */
    public static function parse(mixed $value): ?array
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (!is_string($value)) {
            return null;
        }

        if (!preg_match('/\A[ \t\n\r\x0B\f]*([+-]?)(\d*)(?:\.(\d*))?[ \t\n\r\x0B\f]*\z/', $value, $matches)
            || '' === $matches[2] . ($matches[3] ?? '')) {
            return null;
        }

        $integer = ltrim($matches[2], '0');
        $fraction = rtrim($matches[3] ?? '', '0');

        return ['-' === $matches[1] && '' !== $integer . $fraction, $integer, $fraction];
    }

    /**
     * The digits of an exact decimal without a fraction (e.g. '-12', '0'), or null.
     *
     * @param array{bool, string, string}|null $decimal
     */
    public static function integer_digits(?array $decimal): ?string
    {
        if (null === $decimal || '' !== $decimal[2]) {
            return null;
        }

        return '' === $decimal[1] ? '0' : ($decimal[0] ? '-' : '') . $decimal[1];
    }

    /**
     * Compares two exact decimals: -1, 0 or 1.
     *
     * @param array{bool, string, string} $a
     * @param array{bool, string, string} $b
     */
    public static function compare(array $a, array $b): int
    {
        if ($a[0] !== $b[0]) {
            return $a[0] ? -1 : 1;
        }

        $length = max(strlen($a[2]), strlen($b[2]));
        $cmp = (strlen($a[1]) <=> strlen($b[1]))
            ?: strcmp($a[1], $b[1]) <=> 0
            ?: strcmp(str_pad($a[2], $length, '0'), str_pad($b[2], $length, '0')) <=> 0;

        return $a[0] ? -$cmp : $cmp;
    }

    /**
     * An exact integer as a PHP int when it fits (PHP_INT_MIN to PHP_INT_MAX), else as its digits: what an aggregate
     * of an integer column returns, whatever the driver gave (an int, or the digits of a value beyond the int range: a
     * SUM of BIGINTs, a BIGINT UNSIGNED above PHP_INT_MAX).
     *
     * That is decided on the digits, never with (int), which turns a string beyond the range into PHP_INT_MAX, nor
     * with a float. A string is read as parse() reads it, so a sign, whitespace, leading zeros and a fraction of zeros
     * do not matter ('+007' and '7.00' are 7, '-0' is 0), and the digits that come back are normalized the same way:
     * no leading zero, and no sign but a minus.
     *
     * @return int|string An int, or the digits ('-' first when negative) of an integer beyond the int range
     * @throws ActiveRecordException for a value that is not an exact integer: a fraction that is not all zeros, or no
     *   decimal at all. A DECIMAL column keeps its scale and does not come through here
     */
    public static function to_int_or_string(int|string $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        $digits = self::integer_digits(self::parse($value));

        if (null === $digits) {
            throw new ActiveRecordException(sprintf('ExactDecimal::to_int_or_string() takes an exact integer, got %s', var_export($value, true)));
        }

        $negative = '-' === $digits[0];
        $magnitude = $negative ? substr($digits, 1) : $digits;
        $bound = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;

        // a number with fewer digits than the bound fits, one with more does not, and one with as many fits when
        // its digits do not come after the bound's
        if (strlen($magnitude) < strlen($bound) || (strlen($magnitude) === strlen($bound) && strcmp($magnitude, $bound) <= 0)) {
            return (int) $digits;
        }

        return $digits;
    }

    /**
     * $dividend divided by $divisor, as a decimal string with exactly $scale fractional digits (and no decimal point
     * for a scale of 0), rounded half up, away from zero: a quotient that is half-way between two results, or nearer
     * the one further from zero, takes that one ('1.5' is '2' and '-1.5' is '-2' at scale 0), as ROUND() does on an
     * exact value in MySQL, MariaDB and Postgres. A quotient that rounds to zero has no sign: '-0.00001' at scale 4 is
     * '0.0000'.
     *
     * The DECIMAL division and the AVG() of MySQL and MariaDB do the same, except that they truncate when the scale of
     * the result is 9, 18 or 27 (an artifact of their base-10^9 storage, found by comparing them with this method on
     * random cases), and that MariaDB writes a negative quotient that rounds to zero as '-0.000'. This method copies
     * neither: it rounds the same way at every scale, on every adapter.
     *
     * It is a long division of the digits of the dividend, taken to one digit past the scale. That digit of the
     * quotient is exact, and it alone decides the rounding (5 or more rounds up), so no remainder is compared and no
     * float is made. The dividend may have any number of digits, and the divisor may be as big as PHP_INT_MAX (see
     * wide_step()).
     *
     * @param int|string $dividend An int, or a decimal string as parse() reads it, whatever its size and number of fraction digits
     * @param int $divisor Above 0
     * @param int $scale The number of fractional digits of the result: 0 or more
     * @throws ActiveRecordException for a divisor that is not above 0, a negative scale, or a dividend that is no decimal
     */
    public static function divide(int|string $dividend, int $divisor, int $scale): string
    {
        if ($divisor <= 0) {
            throw new ActiveRecordException(sprintf('ExactDecimal::divide() takes a divisor above 0, got %d', $divisor));
        }

        if ($scale < 0) {
            throw new ActiveRecordException(sprintf('ExactDecimal::divide() takes a scale of 0 or more, got %d', $scale));
        }

        $decimal = self::parse($dividend);

        if (null === $decimal) {
            throw new ActiveRecordException(sprintf('ExactDecimal::divide() takes an integer or a plain decimal string as the dividend, got %s', var_export($dividend, true)));
        }

        [$negative, $integer, $fraction] = $decimal;

        // the dividend as a whole number of units of one digit past the scale: its integer digits, then its fraction
        // cut or padded to scale + 1 digits. What is cut off changes no digit of the quotient up to that one, because
        // the integer part of x / d is the integer part of (the integer part of x) / d
        $quotient = self::long_division($integer . str_pad(substr($fraction, 0, $scale + 1), $scale + 1, '0'), $divisor);

        // the last digit of the quotient is the one past the scale: it rounds, and the others are the result
        $digits = substr($quotient, 0, -1);

        if ((int) $quotient[-1] >= 5) {
            $digits = self::increment($digits);
        }

        // at least one integer digit, then the scale's fractional digits
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, strlen($digits) - $scale), '0');
        $fractional = substr($digits, strlen($digits) - $scale);

        return ($negative && '' !== ltrim($digits, '0') ? '-' : '')
            . ('' === $whole ? '0' : $whole)
            . (0 < $scale ? '.' . $fractional : '');
    }

    /**
     * Divides a whole number, given as its digits, by $divisor (above 0), and gives the quotient as digits of the same
     * length, with the leading zeros that the number has. The remainder is not kept: see divide().
     */
    private static function long_division(string $digits, int $divisor): string
    {
        // a digit step makes 10 * remainder + digit, and the remainder is below the divisor: it is an int as long as
        // the divisor is at most PHP_INT_MAX / 10 (beyond that PHP would silently turn it into a float)
        $wide = $divisor > intdiv(PHP_INT_MAX, 10);
        $quotient = '';
        $remainder = 0;

        for ($i = 0, $length = strlen($digits); $i < $length; ++$i) {
            $digit = (int) $digits[$i];

            if ($wide) {
                [$part, $remainder] = self::wide_step($remainder, $digit, $divisor);
            } else {
                $current = $remainder * 10 + $digit;
                $part = intdiv($current, $divisor);
                $remainder = $current % $divisor;
            }

            $quotient .= $part;
        }

        return $quotient;
    }

    /**
     * One digit step of a long division by a divisor above PHP_INT_MAX / 10, where 10 * remainder + digit may not fit
     * an int. The sum is built one addend at a time (the remainder ten times, then the digit) and kept below the
     * divisor: an addend that would take the sum to the divisor or beyond is added as $sum - ($divisor - $addend)
     * instead, and a wrap is counted ($divisor - $addend is above 0, so no number goes beyond PHP_INT_MAX). The wraps
     * are the quotient digit, and the sum is the new remainder.
     *
     * @param int $remainder Below the divisor
     * @param int $digit 0 to 9
     * @return array{int, int} The quotient digit (0 to 9) and the new remainder
     */
    private static function wide_step(int $remainder, int $digit, int $divisor): array
    {
        $quotient = 0;
        $sum = 0;

        for ($i = 0; $i <= 10; ++$i) {
            $addend = 10 === $i ? $digit : $remainder;

            if ($sum >= $divisor - $addend) {
                $sum -= $divisor - $addend;
                ++$quotient;
            } else {
                $sum += $addend;
            }
        }

        return [$quotient, $sum];
    }

    /**
     * The digits of a whole number plus one ('' is 0): the 9s at its end become 0s, and carry.
     */
    private static function increment(string $digits): string
    {
        $i = strlen($digits) - 1;

        while ($i >= 0 && '9' === $digits[$i]) {
            $digits[$i] = '0';
            --$i;
        }

        if ($i < 0) {
            return '1' . $digits;
        }

        $digits[$i] = (string) ((int) $digits[$i] + 1);

        return $digits;
    }
}
