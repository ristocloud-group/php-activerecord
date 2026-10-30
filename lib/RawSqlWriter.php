<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Writes a SELECT with the values it binds in it, for Query::to_raw_sql(): each bind marker of the SQL of an
 * SQLBuilder is replaced, in order, by the next of its bind values, as the adapter binds it: a value that the
 * connection binds as an integer bare (see Connection::binds_as_integer()), every other value as quoted text. The
 * markers are read as PDO reads them, in one pass, so what is written is never read again.
 *
 * It also reads SQL written by hand for a statement terminator, by the same reading of its strings and comments (see
 * holds_statement_terminator()).
 *
 * @internal Serves Query and QueryCompiler; not a supported API.
 * @package ActiveRecord
 */
final class RawSqlWriter
{
    /**
     * A string in single quotes, in which a backslash escapes the next character. A quote doubled in it ('it''s')
     * reads as two strings side by side, with nothing between them.
     */
    private const string SINGLE_QUOTED = '\'(?:[^\'\\\\]++|\\\\.)*+\'';

    /** A string, or an identifier, in double quotes, read as a string in single quotes is. */
    private const string DOUBLE_QUOTED = '"(?:[^"\\\\]++|\\\\.)*+"';

    /** A comment from -- to the end of the line. */
    private const string DASH_COMMENT = '--[^\r\n]*+';

    /** A block comment, to the first close. */
    private const string BLOCK_COMMENT = '\/\*.*?\*\/';

    /**
     * What to_raw_sql() reads in the SQL of a statement, as PDO reads it: a string in single or double quotes (in
     * which a backslash escapes the next character), and a comment (from -- to the end of the line, or a block
     * comment), which are left as they are; and ?? and ?, the longer first. A ? outside the strings and the
     * comments is a bind marker, and ?? stands for a ? of the statement.
     */
    private const string BIND_MARKER = '/' . self::SINGLE_QUOTED . '|' . self::DOUBLE_QUOTED . '|' . self::DASH_COMMENT . '|' . self::BLOCK_COMMENT . '|\?\??/s';

    /**
     * What holds_statement_terminator() reads in SQL written by hand: the strings and the comments of BIND_MARKER, and
     * besides an identifier in backticks (MySQL, MariaDB and SQLite), a comment from # to the end of the line (MySQL and
     * MariaDB) and a dollar-quoted string of Postgres ($$...$$, or $tag$...$tag$ with a tag of the letters, digits and
     * underscores of an identifier, not a digit first), which are left as they are; and ;. A ; outside them all is a
     * statement terminator. A $ that opens no dollar quote, such as that of the parameter $1, is read as any character.
     */
    private const string STATEMENT_TERMINATOR = '/' . self::SINGLE_QUOTED . '|' . self::DOUBLE_QUOTED . '|`[^`]*+`|' . self::DASH_COMMENT . '|#[^\r\n]*+|' . self::BLOCK_COMMENT . '|\$((?:[A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*+)?)\$.*?\$\1\$|;/s';

    /**
     * @param Table $table The table of the model, whose connection quotes the values and whose columns format its dates
     * @param string $model_class The model, as the messages of the exceptions name it
     */
    public function __construct(private readonly Table $table, private readonly string $model_class) {}

    /**
     * Whether SQL written by hand holds a statement terminator: a ; outside its strings, its quoted identifiers and its
     * comments, read as STATEMENT_TERMINATOR reads them, the union of what MySQL, MariaDB, Postgres and SQLite quote
     * and comment out. MySQL and MariaDB end the statement there and run what follows it as another statement, and
     * SQLite prepares the statement up to it and drops the rest, so the clauses after it are lost; Postgres refuses
     * the statement. A ; in a value is none: a value is bound, never written in the SQL.
     *
     * @internal Serves QueryCompiler::refuse_statement_terminator(); not a supported API.
     * @throws ActiveRecordException for SQL that the pattern cannot read
     */
    public static function holds_statement_terminator(string $sql): bool
    {
        // most SQL holds no ; at all
        if (!str_contains($sql, ';')) {
            return false;
        }

        if (false === preg_match_all(self::STATEMENT_TERMINATOR, $sql, $matches)) {
            throw new ActiveRecordException(sprintf("The SQL '%s' could not be read for a statement terminator: %s", $sql, preg_last_error_msg()));
        }

        return in_array(';', $matches[0], true);
    }

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
     * $value as SQL: what to_raw_sql() writes in place of a bind marker (see to_raw_sql()), as the adapter binds it.
     * A value that the connection binds as an integer is written bare (a bool as the 0 or 1 it turns into), and
     * every other value that is not null as the quoted text that is bound.
     *
     * @throws ActiveRecordException for a value that is no SQL
     */
    private function sql_literal(mixed $value, Connection $connection): string
    {
        return match (true) {
            null === $value => 'NULL',
            $connection->binds_as_integer($value) => (string) (int) $value,
            is_bool($value) => $connection->escape($value ? '1' : '0'),
            // a number that is none, by name: PHP 8.5 warns when it is cast to a string
            is_float($value) && !is_finite($value) => $connection->escape(is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')),
            is_scalar($value), $value instanceof \Stringable => $connection->escape((string) $value),
            default => throw new ActiveRecordException(sprintf('to_raw_sql() cannot write a %s as SQL, in %s', get_debug_type($value), $this->model_class)),
        };
    }
}
