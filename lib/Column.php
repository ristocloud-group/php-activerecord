<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Class for a table column.
 *
 * @package ActiveRecord
 */
class Column
{
    // types for $type
    public const STRING	= 1;
    public const INTEGER	= 2;
    public const DECIMAL	= 3;
    public const DATETIME	= 4;
    public const DATE		= 5;
    public const TIME		= 6;
    public const BOOLEAN	= 7;

    /**
     * Map a type to an column type.
     * @static
     * @var array<string, int>
     */
    public static $TYPE_MAPPING = [
        'datetime'	=> self::DATETIME,
        'timestamp'	=> self::DATETIME,
        'date'		=> self::DATE,
        'time'		=> self::TIME,

        'int'		=> self::INTEGER,
        'tinyint'	=> self::INTEGER,
        'smallint'	=> self::INTEGER,
        'mediumint'	=> self::INTEGER,
        'bigint'	=> self::INTEGER,

        'float'		=> self::DECIMAL,
        'double'	=> self::DECIMAL,
        'numeric'	=> self::DECIMAL,
        'decimal'	=> self::DECIMAL,
        'dec'		=> self::DECIMAL,

        // Postgres `boolean` / SQLite `boolean`/`bool` declarations. MySQL/
        // MariaDB report tinyint(1) instead — MysqlAdapter maps that display
        // width to BOOLEAN by convention (Rails parity).
        'boolean'	=> self::BOOLEAN,
        'bool'		=> self::BOOLEAN];

    /**
     * The true name of this column.
     * @var string
     */
    public $name;

    /**
     * The inflected name of this columns .. hyphens/spaces will be => _.
     * @var string
     */
    public $inflected_name;

    /**
     * The type of this column: STRING, INTEGER, ...
     * @var integer
     */
    public $type;

    /**
     * The raw database specific type.
     * @var string
     */
    public $raw_type;

    /**
     * The maximum length of this column.
     * @var int|null
     */
    public $length;

    /**
     * True if this column allows null.
     * @var boolean
     */
    public $nullable;

    /**
     * True if this column is a primary key.
     * @var boolean
     */
    public $pk;

    /**
     * The default value of the column.
     * @var mixed
     */
    public $default;

    /**
     * True if this column is set to auto_increment.
     * @var boolean
     */
    public $auto_increment;

    /**
     * Name of the sequence to use for this column if any.
     * @var string|null
     */
    public $sequence;

    /**
     * Casts a value to the column's type.
     *
     * @param mixed $value The value to cast
     * @param Connection $connection The Connection this column belongs to
     * @return mixed type-casted value
     */
    public function cast($value, $connection)
    {
        if ($value === null) {
            return null;
        }

        switch ($this->type) {
            case self::STRING:	return (string) $value;
            case self::INTEGER:
                // A value beyond the int range becomes a string, never a wrong
                // int (#44). (int) would clamp an integer string to PHP_INT_MAX
                // or PHP_INT_MIN (e.g. a MySQL BIGINT UNSIGNED above
                // PHP_INT_MAX, which PDO returns as a string) and wrap a finite
                // float into an unrelated int (PHP 8.5 also warns). Values that
                // fit, NAN and ±INF are cast exactly as before.
                if (is_string($value) && is_numeric($value) && false === strpbrk($value, '.eE') && is_float($value + 0)) {
                    // an integer numeric string PHP cannot fit in an int (+ 0
                    // yields a float): kept verbatim
                    return $value;
                }

                // a finite float outside [PHP_INT_MIN, PHP_INT_MAX] (PHP_INT_MAX + 1
                // is computed exactly as a float: 2**63 on 64-bit) is a whole
                // number: give its exact decimal digits. Binding the float itself
                // would keep only `precision` (14) significant digits.
                if (is_float($value) && is_finite($value) && !($value >= PHP_INT_MIN && $value < PHP_INT_MAX + 1)) {
                    return sprintf('%.0f', $value);
                }

                return (int) $value;
            case self::DECIMAL:	return (float) $value;
            case self::BOOLEAN:
                // Native PHP bool. Textual forms need explicit handling:
                // Postgres sends 't'/'f' (and 'true'/'false' for introspected
                // defaults), and (bool)'f' / (bool)'false' / (bool)'0' would
                // all be TRUE under a naive cast. Bools are converted back to
                // ints at bind time (Connection::bind_values).
                if (is_string($value)) {
                    return match (strtolower(trim($value))) {
                        't', 'true', '1' => true,
                        'f', 'false', '0', '' => false,
                        default => (bool) $value,
                    };
                }

                return (bool) $value;
            case self::DATETIME:
            case self::DATE:
                if (!$value) {
                    return null;
                }

                if ($value instanceof DateTime) {
                    return $value;
                }

                if ($value instanceof \DateTime) {
                    return new DateTime($value->format('Y-m-d H:i:s T'));
                }

                return $connection->string_to_datetime($value);
        }
        return $value;
    }

    /**
     * Sets the $type member variable.
     * @return mixed
     */
    public function map_raw_type()
    {
        if ($this->raw_type == 'integer') {
            $this->raw_type = 'int';
        }

        if (array_key_exists($this->raw_type, self::$TYPE_MAPPING)) {
            $this->type = self::$TYPE_MAPPING[$this->raw_type];
        } else {
            $this->type = self::STRING;
        }

        return $this->type;
    }
}
