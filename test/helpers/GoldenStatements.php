<?php

use ActiveRecord\Config;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * The statements a call sends to the database, and the golden files that pin them
 * (test/golden/<connection>.json, see GoldenSqlTest).
 *
 * The capture sits at the Connection::query() boundary: every Connection logs each
 * statement it runs, with its bind values, to the Monolog logger of ActiveRecord\Config.
 * So it sees the SQL and the binds exactly as they leave the library, whichever code
 * built them.
 */
final class GoldenStatements
{
    /** What mask() writes for a timestamp that a write generated */
    private const string NOW = '<now>';

    /** A datetime as the adapters format one: 'Y-m-d H:i:s', plus ' T' on Postgres */
    private const string DATETIME = '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}( [A-Z]{2,5})?\z/';

    private const int JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Runs $case and returns one entry per Connection::query() call it made, in order. An
     * exception it throws is caught and appended as the last entry: a case that is meant
     * to fail records how.
     *
     * @param callable(): mixed $case
     * @return list<array{sql: string, values: list<mixed>}|array{exception: class-string<\Throwable>, message: string}>
     */
    public static function capture(callable $case): array
    {
        $logger = Config::instance()->get_logger();

        if (!$logger instanceof Logger) {
            throw new \RuntimeException('GoldenStatements::capture() needs the Monolog logger that test/helpers/config.php sets on ActiveRecord\Config.');
        }

        $handler = new TestHandler();
        $handler->pushProcessor(self::detach_binds(...));
        $logger->pushHandler($handler);
        $thrown = null;

        try {
            $case();
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $logger->popHandler();
        }

        $statements = [];

        foreach ($handler->getRecords() as $record) {
            // Connection::query() logs the SQL at debug level, its binds as the context
            if (Level::Debug === $record->level && array_key_exists('values', $record->context)) {
                $statements[] = ['sql' => $record->message, 'values' => $record->context['values']];
            }
        }

        if (null !== $thrown) {
            $statements[] = ['exception' => $thrown::class, 'message' => self::message_of($thrown)];
        }

        return $statements;
    }

    /**
     * Replaces, in the bind values, each datetime string within a day of now with '<now>':
     * the clock cannot be frozen yet, and a write binds the time it ran. The fixtures hold
     * past dates only, so this masks exactly the timestamps a write generated.
     *
     * @param list<array<string, mixed>> $statements as returned by capture()
     * @return list<array<string, mixed>>
     */
    public static function mask(array $statements): array
    {
        $now = time();

        return array_map(static function (array $statement) use ($now): array {
            if (is_array($statement['values'] ?? null)) {
                $statement['values'] = array_map(
                    static fn(mixed $value): mixed => self::is_recent_datetime($value, $now) ? self::NOW : $value,
                    $statement['values'],
                );
            }

            return $statement;
        }, $statements);
    }

    /**
     * @return array<string, list<array<string, mixed>>> the statements of each case by name; none while the connection has no golden file
     */
    public static function load(string $connection): array
    {
        $path = self::path($connection);

        if (!is_file($path)) {
            return [];
        }

        $golden = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($golden)) {
            throw new \UnexpectedValueException("$path does not hold a JSON object.");
        }

        return $golden;
    }

    /**
     * Writes the golden file of a connection with its keys sorted, so a run that records
     * the same statements leaves no diff.
     *
     * @param array<string, list<array<string, mixed>>> $golden
     */
    public static function save(string $connection, array $golden): void
    {
        file_put_contents(self::path($connection), json_encode(self::sort_keys($golden), self::JSON_FLAGS) . "\n");
    }

    private static function path(string $connection): string
    {
        return dirname(__DIR__) . "/golden/$connection.json";
    }

    /**
     * The bind values of a logged statement as they are when it is logged. The record can
     * share reference slots with the array that goes on to PDO, and where PDO binds every
     * value as a string (MySQL, MariaDB, Postgres) it converts an int in place, after the
     * statement was logged: Author::count(1, 2) logs [1, 2], which would read back as
     * ['1', '2']. A copy made here, as the record is handled, shares nothing.
     */
    private static function detach_binds(LogRecord $record): LogRecord
    {
        $values = $record->context['values'] ?? null;

        if (!is_array($values)) {
            return $record;
        }

        return $record->with(context: ['values' => array_map(static fn(mixed $value): mixed => $value, $values)] + $record->context);
    }

    /**
     * The message a golden file can pin: its first line, without the " in <file>:<line>"
     * that ends an exception's own string form. A DatabaseException made from a PDOException
     * holds that form, with the whole stack trace of the call behind it, and these change
     * with every edit and with the directory the suite runs from.
     */
    private static function message_of(\Throwable $e): string
    {
        [$first_line] = explode("\n", $e->getMessage(), 2);

        return preg_replace('~ in (?:/|[A-Za-z]:\\\\)[^\n]*:\d+\z~', '', $first_line) ?? $first_line;
    }

    private static function is_recent_datetime(mixed $value, int $now): bool
    {
        if (!is_string($value) || 1 !== preg_match(self::DATETIME, $value)) {
            return false;
        }

        $time = strtotime($value);

        return false !== $time && abs($time - $now) <= 86400;
    }

    /**
     * The keys of every hash sorted; lists keep their order.
     */
    private static function sort_keys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::sort_keys(...), $value);

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
