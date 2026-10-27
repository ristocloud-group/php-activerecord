<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Reads what the database returns for the aggregates of a column (spec §5.4), as the one place that tells the PHP type
 * of a MIN, a MAX, a SUM or an AVG by the type of the column. The drivers do not agree on it: MySQL returns a SUM as a
 * DECIMAL string, Postgres a SUM of INTs as an int and of BIGINTs as a numeric string, SQLite a REAL as a float, and
 * the Postgres driver of PHP 8.3 a float8 as a string where that of PHP 8.5 gives a float.
 *
 * A column of the model is of one kind (see kind()), which tells how its aggregates are read:
 * - INTEGER, an exact integer: its MIN, MAX and SUM are an int while the exact value fits PHP_INT_MIN to PHP_INT_MAX,
 *   else the exact digits as a string (ExactDecimal decides on the digits, never with a float);
 * - DECIMAL, an exact decimal: the string the database returns, its scale kept;
 * - FLOATING: a float.
 * The AVG of an INTEGER or DECIMAL column is exact too: the SUM divided by the COUNT (average()); that of a FLOATING
 * column is the database's, as a float. Any other column, and any name that is no column of the model (a joined table's
 * column, the alias of an expression), has no kind: its aggregates are the driver's values, unchanged.
 *
 * A float never reaches ExactDecimal: PHP coerces a float given to its int|string parameters before it runs (1.5 is 1,
 * with a deprecation). So a float that the driver returns for an exact column (SQLite stores a REAL in an INTEGER column
 * when the value has a fraction) is returned as it is.
 *
 * It needs no database, so that the whole table of §5.4 is unit tested with the values each driver gives.
 *
 * @internal Serves Query; not a supported API.
 * @package ActiveRecord
 */
final class AggregateCaster
{
    /** The kind of a column that holds exact integers (see kind()) */
    public const string INTEGER = 'integer';

    /** The kind of a column that holds exact decimals */
    public const string DECIMAL = 'decimal';

    /** The kind of a column that holds floating-point numbers */
    public const string FLOATING = 'floating';

    /**
     * The raw types of the exact decimals, as the adapters report them: MySQL and MariaDB 'decimal' for DECIMAL,
     * NUMERIC, DEC and FIXED; Postgres 'numeric' for both; SQLite the first word of the declared type.
     *
     * @var list<string>
     */
    private const array DECIMAL_TYPES = ['decimal', 'numeric', 'dec'];

    /**
     * The raw types of the floating-point numbers. MySQL and MariaDB report 'float' and 'double' (REAL and DOUBLE
     * PRECISION too), Postgres 'double' for double precision and 'real' for real, SQLite the first word of the declared
     * type; 'double precision' is what no adapter reports now, as a first word, and is here as the spec lists it.
     *
     * @var list<string>
     */
    private const array FLOATING_TYPES = ['float', 'double', 'real', 'double precision'];

    /**
     * The digits that an average has beyond the scale of its SUM: MySQL's div_precision_increment, 4 by default, which
     * gives an INT column an average of 4 decimals and a DECIMAL(20,2) one of 6.
     */
    private const int AVERAGE_EXTRA_SCALE = 4;

    /**
     * The kind of the aggregates of $column, from its type and its raw type, or null for a column that is not a number
     * (text, a date, a boolean) and for no column at all (a name that is no column of the model).
     *
     * The raw type decides for the decimals and the floats, whatever the column's type: DECIMAL covers both, and Postgres's
     * real (float4) and SQLite's REAL have no entry in the type mapping, so they are reported as Column::STRING with the
     * raw type 'real'. An integer is a Column::INTEGER.
     *
     * A database that does not keep the digits of a DECIMAL ($exact_decimals false: SQLite stores a REAL) has no exact
     * decimal: its decimal columns are FLOATING.
     *
     * @return self::INTEGER|self::DECIMAL|self::FLOATING|null
     */
    public static function kind(?Column $column, bool $exact_decimals = true): ?string
    {
        if (null === $column) {
            return null;
        }

        $raw_type = strtolower((string) $column->raw_type);

        return match (true) {
            in_array($raw_type, self::DECIMAL_TYPES, true) => $exact_decimals ? self::DECIMAL : self::FLOATING,
            in_array($raw_type, self::FLOATING_TYPES, true) => self::FLOATING,
            Column::INTEGER === $column->type => self::INTEGER,
            default => null,
        };
    }

    /**
     * What $function of a column of $kind is, from the $value the driver returned for it:
     *
     * | kind | MIN, MAX, SUM | a SUM of no value |
     * |---|---|---|
     * | INTEGER | an int, or the exact digits beyond the int range | 0 |
     * | DECIMAL | the string, as the database returns it | '0' |
     * | FLOATING | a float | 0.0 |
     * | none | the driver's value | null |
     *
     * and a MIN, a MAX and an AVG of no value are null. AVG is read here for the kinds that average in the database
     * (FLOATING, and none); an exact column's is average().
     *
     * A value that is not of the type the kind expects is not hidden: an int or a float for a DECIMAL is the float of it
     * (a database that returns a number for a decimal has no exact one), a float for an INTEGER stays the float it is
     * (SQLite's REAL in an INTEGER column), and the text of a float is read as a float, with Postgres's spellings of NaN
     * and the infinities.
     *
     * @param self::INTEGER|self::DECIMAL|self::FLOATING|null $kind
     * @param 'MIN'|'MAX'|'SUM'|'AVG' $function
     * @return int|float|string|null
     * @throws ActiveRecordException for an INTEGER column whose value is text that is not an exact integer
     */
    public static function cast(?string $kind, string $function, mixed $value): mixed
    {
        if (null === $value) {
            return 'SUM' !== $function ? null : match ($kind) {
                self::INTEGER => 0,
                self::DECIMAL => '0',
                self::FLOATING => 0.0,
                default => null,
            };
        }

        return match ($kind) {
            self::INTEGER => is_int($value) || is_string($value) ? ExactDecimal::to_int_or_string($value) : $value,
            self::DECIMAL => is_string($value) ? $value : self::to_float($value),
            self::FLOATING => self::to_float($value),
            default => $value,
        };
    }

    /**
     * The AVG of an exact column, from the $sum and the $count that `SELECT SUM(col), COUNT(col)` returned: the exact
     * quotient, as a string, rounded half up (away from zero) at the scale of the SUM plus 4 (see ExactDecimal::divide()).
     * The scale is that of the SUM as the driver wrote it, trailing zeros included ('2.50' has 2): a DECIMAL(20,2) column
     * averages to 6 decimals, an INT column to 4. Null for no value: the SUM is NULL, and the COUNT 0.
     *
     * COUNT(col) counts the values, not the rows, so that NULLs do not weigh on the average, as in the database's own.
     *
     * Where the driver gives the SUM as a float (SQLite: its REAL storage), or as an int for a DECIMAL (SQLite: a whole
     * decimal), no exact quotient exists, and the average is the float $sum / $count.
     *
     * @param self::INTEGER|self::DECIMAL $kind
     * @throws ActiveRecordException for a SUM that is text which ExactDecimal does not read as a decimal ('NaN', say)
     */
    public static function average(string $kind, mixed $sum, mixed $count): string|float|null
    {
        $count = (int) $count;

        if (null === $sum || $count < 1) {
            return null;
        }

        if (is_string($sum) || (is_int($sum) && self::INTEGER === $kind)) {
            return ExactDecimal::divide($sum, $count, self::scale($sum) + self::AVERAGE_EXTRA_SCALE);
        }

        return (float) $sum / $count;
    }

    /**
     * The number of digits after the decimal point of $sum, as the driver wrote it: an int has none, and trailing zeros
     * count, which ExactDecimal::parse() does not keep.
     */
    private static function scale(int|string $sum): int
    {
        $sum = trim((string) $sum);
        $point = strrpos($sum, '.');

        return false === $point ? 0 : strlen($sum) - $point - 1;
    }

    /**
     * $value as a float: an int or a float is one, a numeric string is read, and the spellings of NaN and the infinities
     * that Postgres writes as text, which (float) would make 0.0, are those values. Anything else stays as it is.
     */
    private static function to_float(mixed $value): mixed
    {
        return match (true) {
            is_float($value) => $value,
            is_int($value) => (float) $value,
            is_string($value) => match ($value) {
                'NaN' => NAN,
                'Infinity' => INF,
                '-Infinity' => -INF,
                default => is_numeric($value) ? (float) $value : $value,
            },
            default => $value,
        };
    }
}
