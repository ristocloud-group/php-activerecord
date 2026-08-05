<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Templating like class for building SQL statements.
 *
 * Examples:
 * 'name = :name AND author = :author'
 * 'id = IN(:ids)'
 * 'id IN(:subselect)'
 *
 * @package ActiveRecord
 */
class Expressions
{
    public const ParameterMarker = '?';

    /**
     * @var string|null the built SQL fragment, e.g. 'name = ? AND id = ?'
     */
    private $expressions;

    /**
     * @var list<mixed> bind values, positionally aligned with the '?' markers in $expressions
     */
    private $values = [];

    /**
     * @var Connection|null
     */
    private $connection;

    /**
     * Any further arguments beyond $expressions are read positionally via func_get_args(): when
     * $expressions is a string, they are its bind values; when $expressions is an array, a single
     * extra argument is accepted as the glue string used to join conditions (e.g. ', ').
     *
     * @param Connection|null                   $connection  a Connection instance, or null to fall back
     *                                                        to naive quoting
     * @param array<int|string, mixed>|string|null $expressions either a raw SQL fragment with '?' markers,
     *                                                        or a hash of column => value conditions to be
     *                                                        AND/OR-ed together (see {@link build_sql_from_hash})
     */
    public function __construct($connection, $expressions = null /* [, $values ... ] */)
    {
        $values = null;
        $this->connection = $connection;

        if (is_array($expressions)) {
            $glue = func_num_args() > 2 ? func_get_arg(2) : ' AND ';
            [$expressions, $values] = $this->build_sql_from_hash($expressions, $glue);
        }

        if ($expressions != '') {
            if (!$values) {
                $values = array_slice(func_get_args(), 2);
            }

            $this->values = $values;
            $this->expressions = $expressions;
        }
    }

    /**
     * Bind a value to the specific one based index. There must be a bind marker
     * for each value bound or to_s() will throw an exception.
     *
     * @param int   $parameter_number one-based index of the bind marker to set
     * @param mixed $value            the value to bind
     *
     * @return void
     */
    public function bind($parameter_number, $value)
    {
        if ($parameter_number <= 0) {
            throw new ExpressionsException("Invalid parameter index: $parameter_number");
        }

        $values = $this->values;
        $values[$parameter_number - 1] = $value;
        /** @var list<mixed> $values */
        $this->values = $values;
    }

    /**
     * @param list<mixed> $values bind values, positionally aligned with the '?' markers
     *
     * @return void
     */
    public function bind_values($values)
    {
        $this->values = $values;
    }

    /**
     * Returns all the values currently bound.
     *
     * @return list<mixed>
     */
    public function values()
    {
        return $this->values;
    }

    /**
     * Returns the connection object.
     *
     * @return Connection|null
     */
    public function get_connection()
    {
        return $this->connection;
    }

    /**
     * Sets the connection object. It is highly recommended to set this so we can
     * use the adapter's native escaping mechanism.
     *
     * @param Connection|null $connection a Connection instance
     *
     * @return void
     */
    public function set_connection($connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param bool                $substitute whether to inline the actual (quoted) values instead of
     *                                         leaving '?' bind markers in the returned string
     * @param array<string, mixed>|null $options  supports a 'values' key to override the bound values
     *                                             used for substitution
     *
     * @return string
     */
    public function to_s($substitute = false, &$options = null)
    {
        if (!$options) {
            $options = [];
        }

        $values = array_key_exists('values', $options) ? $options['values'] : $this->values;

        $ret = "";
        $replace = [];
        $num_values = count($values);
        $expressions = $this->expressions ?? '';
        $len = strlen($expressions);
        $quotes = 0;

        for ($i = 0,$n = strlen($expressions),$j = 0; $i < $n; ++$i) {
            $ch = $expressions[$i];

            if ($ch == self::ParameterMarker) {
                if ($quotes % 2 == 0) {
                    if ($j > $num_values - 1) {
                        throw new ExpressionsException("No bound parameter for index $j");
                    }

                    $ch = $this->substitute($values, $substitute, $i, $j++);
                }
            } elseif ($ch == '\'' && $i > 0 && $expressions[$i - 1] != '\\') {
                ++$quotes;
            }

            $ret .= $ch;
        }
        return $ret;
    }

    /**
     * @param array<int|string, mixed> &$hash a hash of column => value (or column => list-of-values)
     *                                         conditions to be joined into a SQL fragment
     * @param string                    $glue  the boolean operator used to join each condition, e.g. ' AND '
     *
     * @return array{0: string, 1: list<mixed>} the built SQL fragment and its positional bind values
     */
    private function build_sql_from_hash(array &$hash, string $glue): array
    {
        $sql = $g = "";

        foreach ($hash as $name => $value) {
            if ($this->connection) {
                $name = $this->connection->quote_name((string) $name);
            }

            if (is_array($value)) {
                $sql .= "$g$name IN(?)";
            } elseif (is_null($value)) {
                $sql .= "$g$name IS ?";
            } else {
                $sql .= "$g$name=?";
            }

            $g = $glue;
        }
        return [$sql,array_values($hash)];
    }

    /**
     * @param list<mixed> &$values          the bound values, positionally aligned with the '?' markers
     * @param bool         $substitute       whether to inline the actual (quoted) value(s) instead of
     *                                       returning bind marker(s)
     * @param int          $pos              index of the current '?' marker within $this->expressions
     * @param int          $parameter_index  index of the value to substitute within $values
     *
     * @return mixed a substituted SQL fragment (string), or the original character at $pos when not substituting
     */
    private function substitute(array &$values, bool $substitute, int $pos, int $parameter_index): mixed
    {
        $value = $values[$parameter_index];

        if (is_array($value)) {
            if ($substitute) {
                $ret = '';

                for ($i = 0,$n = count($value); $i < $n; ++$i) {
                    $ret .= ($i > 0 ? ',' : '') . $this->stringify_value($value[$i]);
                }

                return $ret;
            }
            return join(',', array_fill(0, count($value), self::ParameterMarker));
        }

        if ($substitute) {
            return $this->stringify_value($value);
        }

        return ($this->expressions ?? '')[$pos];
    }

    /**
     * @param mixed $value a single bind value
     *
     * @return mixed the value rendered as a literal SQL fragment: the string "NULL" for null, a quoted
     *                string for strings, or the original scalar value passed through unchanged otherwise
     */
    private function stringify_value(mixed $value): mixed
    {
        if (is_null($value)) {
            return "NULL";
        }

        return is_string($value) ? $this->quote_string($value) : $value;
    }

    private function quote_string(string $value): string
    {
        if ($this->connection) {
            return $this->connection->escape($value);
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }
}
