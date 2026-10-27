<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Writes a SELECT with the values it binds in it, for Query::to_raw_sql(): each bind marker of the SQL of an
 * SQLBuilder is replaced, in order, by the next of its bind values, as the quoted text the library binds for it. The
 * markers are read as PDO reads them, in one pass, so what is written is never read again.
 *
 * @internal Serves Query; not a supported API.
 * @package ActiveRecord
 */
final class RawSqlWriter
{
    /**
     * What to_raw_sql() reads in the SQL of a statement, as PDO reads it: a string in single or double quotes (in
     * which a backslash escapes the next character), and a comment (from -- to the end of the line, or a block
     * comment), which are left as they are; and ?? and ?, the longer first. A ? outside the strings and the
     * comments is a bind marker, and ?? stands for a ? of the statement.
     */
    private const string BIND_MARKER = '/\'(?:[^\'\\\\]++|\\\\.)*+\'|"(?:[^"\\\\]++|\\\\.)*+"|--[^\r\n]*+|\/\*.*?\*\/|\?\??/s';

    /**
     * @param Table $table The table of the model, whose connection quotes the values and whose columns format its dates
     * @param string $model_class The model, as the messages of the exceptions name it
     */
    public function __construct(private readonly Table $table, private readonly string $model_class) {}

    /**
     * The SELECT of $sql with its values written in place of its bind markers (see Query::to_raw_sql()).
     *
     * @throws ActiveRecordException for markers and values that do not match, or a value that is no SQL
     */
    public function write(SQLBuilder $sql): string
    {
        $values = $this->table->format_date_bind_values($sql->select_bind_values());
        $connection = $this->table->connection();
        $markers = 0;

        $raw = preg_replace_callback(self::BIND_MARKER, function (array $match) use (&$markers, $values, $connection): string {
            if ('?' !== $match[0]) {
                // a string and a comment as they are, and ?? as the ? it stands for
                return '??' === $match[0] ? '?' : $match[0];
            }

            // a marker that has no value is counted, and the statement refused below
            return $this->sql_literal($values[$markers++] ?? null, $connection);
        }, $sql->to_s());

        if (null === $raw) {
            throw new ActiveRecordException(sprintf('to_raw_sql() could not read the SELECT of %s: %s', $this->model_class, preg_last_error_msg()));
        }

        if ($markers !== count($values)) {
            throw new ActiveRecordException(sprintf('to_raw_sql() found %d bind markers in the SELECT of %s, for %d values', $markers, $this->model_class, count($values)));
        }

        return $raw;
    }

    /**
     * $value as SQL: what to_raw_sql() writes in place of a bind marker (see to_raw_sql()). Every value that is
     * not null is the quoted text that the library binds for it.
     *
     * @throws ActiveRecordException for a value that is no SQL
     */
    private function sql_literal(mixed $value, Connection $connection): string
    {
        return match (true) {
            null === $value => 'NULL',
            is_bool($value) => $connection->escape($value ? '1' : '0'),
            // a number that is none, by name: PHP 8.5 warns when it is cast to a string
            is_float($value) && !is_finite($value) => $connection->escape(is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')),
            is_scalar($value), $value instanceof \Stringable => $connection->escape((string) $value),
            default => throw new ActiveRecordException(sprintf('to_raw_sql() cannot write a %s as SQL, in %s', get_debug_type($value), $this->model_class)),
        };
    }
}
