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
 * It also reads SQL written by hand for a statement terminator, and for a block comment left open, as the database of a
 * connection reads its strings and its comments, with the sub-patterns of that reading where they agree (see
 * holds_statement_terminator() and leaves_block_comment_open()).
 *
 * @internal Serves Query and QueryCompiler; not a supported API.
 * @package ActiveRecord
 */
final class RawSqlWriter
{
    /**
     * A string in single quotes, in which a backslash escapes the next character (as PDO reads it, and MySQL and MariaDB
     * in their default sql_mode). A quote doubled in it ('it''s') reads as two strings side by side, with nothing between
     * them.
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
     * The opener of a block comment that the readings below match where no closed comment of theirs does: one that
     * nothing closes, which runs to the end of the SQL, an open block comment (see leaves_block_comment_open()).
     */
    private const string OPEN_COMMENT = '\/\*';

    /** An identifier in backticks (MySQL, MariaDB and SQLite), a doubled backtick in it reading as two side by side. */
    private const string BACKTICKED = '`[^`]*+`';

    /**
     * How MySQL and MariaDB read SQL written by hand, for a statement terminator (see holds_statement_terminator()), in
     * their default sql_mode: strings in single and double quotes with backslash escapes, identifiers in backticks, a
     * comment from # to the end of the line, a comment from -- only where a space or a control character follows the
     * dashes (0--1 is 0 - -1), and a block comment, which does not nest, but for an executable one (opened by /*! or
     * /*M!), whose text they run as SQL and which is read as such: its opener alone, when a close follows it. There is
     * no dollar quote: $ is a character of identifiers and of variables (@a$$). And ;, and a /* that nothing closes, an
     * open block comment.
     */
    private const string MYSQL_TERMINATOR = '/' . self::SINGLE_QUOTED . '|' . self::DOUBLE_QUOTED . '|' . self::BACKTICKED . '|#[^\n]*+|--(?=[\x00-\x20\x7F])[^\n]*+|\/\*(?!!|M!).*?\*\/|\/\*M?!(?=.*?\*\/)|' . self::OPEN_COMMENT . '|;/s';

    /**
     * How Postgres reads it: a standard string in single quotes, in which a backslash is a character; an escape string
     * (E'...', the E not part of an identifier), in which a backslash escapes the next character and a doubled quote is
     * a quote; an identifier in double quotes; a dollar-quoted string ($$...$$, or $tag$...$tag$ with a tag of the
     * letters, digits and underscores of an identifier, not a digit first) opened by a $ that follows no character of an
     * identifier (a$$ is an identifier, $1 a parameter); a comment from -- to the end of the line; and a block comment,
     * which nests. # is an operator, and a backtick no quote. And ;, and a /* that nothing closes at its depth, an
     * open block comment.
     */
    private const string PGSQL_TERMINATOR = '/(?<![A-Za-z0-9_$\x80-\xFF])[Ee]\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|\'[^\']*+\'|"[^"]*+"|(?<![A-Za-z0-9_$\x80-\xFF])\$(?<tag>(?:[A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*+)?)\$.*?\$\k<tag>\$|--[^\r\n]*+|(?<comment>\/\*(?:[^\/*]++|\*(?!\/)|\/(?!\*)|(?&comment))*+\*\/)|' . self::OPEN_COMMENT . '|;/s';

    /**
     * How SQLite reads it: a parameter, read first as one token as SQLite's tokenizer reads it: #, @ or :, or a $ that
     * follows no character of an identifier (a$b is an identifier), then a name of the letters, digits, underscores, $
     * and :: of an identifier (#a, $$a, @a$$, :a::b), then a Tcl-style suffix from ( to the first ) or whitespace, in
     * which a quote, a bracket or a ; is the parameter's ($a(') and $a(;) are each one parameter); a string in single
     * quotes and an identifier in double quotes, in backticks or in brackets, where a backslash escapes nothing; a comment
     * from -- to the end of the line; and a block comment, which does not nest. There is no dollar quote. And ;, and a
     * /* that nothing closes, an open block comment.
     */
    private const string SQLITE_TERMINATOR = '/(?:(?<![A-Za-z0-9_$\x80-\xFF])\$|[#@:])(?:[A-Za-z0-9_$\x80-\xFF]|::)++(?:\([^\s)]*+\)?)?|\'[^\']*+\'|"[^"]*+"|' . self::BACKTICKED . '|\[[^\]]*+\]|--[^\n]*+|' . self::BLOCK_COMMENT . '|' . self::OPEN_COMMENT . '|;/s';

    /**
     * @param Table $table The table of the model, whose connection quotes the values and whose columns format its dates
     * @param string $model_class The model, as the messages of the exceptions name it
     */
    public function __construct(private readonly Table $table, private readonly string $model_class) {}

    /**
     * Whether SQL written by hand holds a statement terminator for $connection: a ; outside its strings, its quoted
     * identifiers and its comments, read as the database of the connection reads them, in one pass (MYSQL_TERMINATOR
     * for MySQL and MariaDB, PGSQL_TERMINATOR, SQLITE_TERMINATOR). MySQL and MariaDB end the statement there and run
     * what follows it as another statement, and SQLite prepares the statement up to it and drops the rest, so the
     * clauses after it are lost; Postgres refuses the statement. A connection of no adapter of the library is read by
     * the three, and holds one when any of them finds one. A ; in a value is none: a value is bound, never written in
     * the SQL.
     *
     * @internal Serves QueryCompiler::refuse_statement_terminator(); not a supported API.
     * @throws ActiveRecordException for SQL that the pattern cannot read
     */
    public static function holds_statement_terminator(string $sql, Connection $connection): bool
    {
        // most SQL holds no ; at all
        return str_contains($sql, ';') && self::reads(';', $sql, $connection, 'a statement terminator');
    }

    /**
     * Whether SQL written by hand leaves a block comment open for $connection: a /* outside its strings, its quoted
     * identifiers and its comments that nothing closes, read as the database of the connection reads them, in the pass
     * of holds_statement_terminator() (MySQL, MariaDB and SQLite do not nest block comments, and read the first close as
     * theirs; Postgres nests them, and a comment in a comment closed once is still open there; the text of MySQL's
     * executable comment, /*! or /*M!, is SQL, open until its close). SQLite runs such a comment to the end of the
     * statement, so the clauses after the SQL are lost; MySQL, MariaDB and Postgres refuse the statement. A connection
     * of no adapter of the library is read by the three, and leaves one open when any of them finds one.
     *
     * @internal Serves QueryCompiler::refuse_statement_terminator(); not a supported API.
     * @throws ActiveRecordException for SQL that the pattern cannot read
     */
    public static function leaves_block_comment_open(string $sql, Connection $connection): bool
    {
        // most SQL holds no block comment at all
        return str_contains($sql, '/*') && self::reads('/*', $sql, $connection, 'an open block comment');
    }

    /**
     * Whether a reading of $sql by $connection (see holds_statement_terminator()) reads $token, the ; or the /* of an
     * open block comment, outside the strings, the quoted identifiers and the comments: each match of a reading is one of
     * those, the opener of an executable comment, or a token.
     *
     * @param string $what The token as the message of the exception names it
     * @throws ActiveRecordException for SQL that the pattern cannot read
     */
    private static function reads(string $token, string $sql, Connection $connection, string $what): bool
    {
        foreach (self::readings($connection) as $reading) {
            if (false === preg_match_all($reading, $sql, $matches)) {
                throw new ActiveRecordException(sprintf("The SQL '%s' could not be read for %s: %s", $sql, $what, preg_last_error_msg()));
            }

            if (in_array($token, $matches[0], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $sql as each reading of $connection reads it (see holds_statement_terminator(); the three readings for a connection
     * of no adapter of the library), with its strings, its quoted identifiers, its dollar quotes and its parameters
     * written as the word "literal", and its comments as a space: what is left is the SQL outside them, as the database
     * reads it. The opener of an executable comment of MySQL and MariaDB (/*!, /*M!) is a space, its text the SQL it
     * runs, and so is its close.
     *
     * @internal Serves QueryCompiler::refuse_position_in_own_select(); not a supported API.
     * @return list<string>
     * @throws ActiveRecordException for SQL that the pattern cannot read
     */
    public static function without_literals_and_comments(string $sql, Connection $connection): array
    {
        $texts = [];

        foreach (self::readings($connection) as $reading) {
            $mysql = self::MYSQL_TERMINATOR === $reading;
            $executable = false;
            $text = preg_replace_callback($reading, function (array $match) use ($mysql, &$executable): string {
                $token = $match[0];

                if (';' === $token) {
                    return $token;
                }

                if (str_starts_with($token, '--') || str_starts_with($token, '/*') || ($mysql && str_starts_with($token, '#'))) {
                    $executable = $executable || ($mysql && 1 === preg_match('/^\/\*M?!$/', $token));

                    return ' ';
                }

                return ' literal ';
            }, $sql);

            if (null === $text) {
                throw new ActiveRecordException(sprintf("The SQL '%s' could not be read: %s", $sql, preg_last_error_msg()));
            }

            $texts[] = $executable ? str_replace('*/', ' ', $text) : $text;
        }

        return $texts;
    }

    /**
     * The readings of SQL written by hand for $connection: MYSQL_TERMINATOR for MySQL and MariaDB, PGSQL_TERMINATOR,
     * SQLITE_TERMINATOR, or the three for a connection of no adapter of the library.
     *
     * @return list<string>
     */
    private static function readings(Connection $connection): array
    {
        return match (true) {
            $connection instanceof MysqlAdapter => [self::MYSQL_TERMINATOR],
            $connection instanceof PgsqlAdapter => [self::PGSQL_TERMINATOR],
            $connection instanceof SqliteAdapter => [self::SQLITE_TERMINATOR],
            default => [self::MYSQL_TERMINATOR, self::PGSQL_TERMINATOR, self::SQLITE_TERMINATOR],
        };
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
