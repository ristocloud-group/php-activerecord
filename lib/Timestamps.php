<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The library's one timestamp policy: where "now" comes from, which columns of a table are its
 * timestamps, what an INSERT or an UPDATE fills in, and the per-class suspension behind
 * Model::without_timestamps(). Whatever sets created_at or updated_at of its own accord asks
 * here, so that they all agree.
 *
 * A timestamp column is a column whose attribute name (its inflected name, see Column) is
 * created_at or updated_at. That is not always its name: "created at" is created_at (#92).
 *
 * @internal Serves the library's writes; not a supported API. Model::without_timestamps() is the
 *   public entry of the suspension.
 * @package ActiveRecord
 */
final class Timestamps
{
    /**
     * The time now() answers while a test has frozen it, else null.
     */
    private static ?string $frozen = null;

    /**
     * How many suspend() calls are running for each class: a class is suspended while it has one.
     *
     * @var array<string, int>
     */
    private static array $suspended = [];

    /**
     * The one source of "now": the current time as a database datetime, unless freeze() fixed it.
     */
    public static function now(): string
    {
        return self::$frozen ?? date('Y-m-d H:i:s');
    }

    /**
     * Fixes now() at $now, or releases it with null. For tests, which must release it.
     */
    public static function freeze(?string $now): void
    {
        self::$frozen = $now;
    }

    /**
     * Runs $fn with the timestamps of $class suspended, and returns what it returns.
     *
     * The suspension ends when $fn does, whether it returns or throws. Calls nest: a class stays
     * suspended until the outermost one ends. It is the class named that is suspended, not a
     * subclass of it, nor a parent.
     *
     * @template TResult
     * @param callable(): TResult $fn
     * @return TResult
     */
    public static function suspend(string $class, callable $fn): mixed
    {
        self::$suspended[$class] = (self::$suspended[$class] ?? 0) + 1;

        try {
            return $fn();
        } finally {
            if (0 === --self::$suspended[$class]) {
                unset(self::$suspended[$class]);
            }
        }
    }

    /**
     * True while a suspend() call for exactly $class is running.
     */
    public static function suspended(string $class): bool
    {
        return isset(self::$suspended[$class]);
    }

    /**
     * The timestamp columns of $table: the column whose attribute name is created_at, and the one
     * whose attribute name is updated_at (null for a table with no such column). A name here is
     * the column's own, which a statement has to use, and can differ from the attribute's
     * ('created at', #92). Where two columns share an attribute name the first one is taken, as
     * Table::get_column_by_inflected_name() does.
     *
     * @return array{created_at: ?string, updated_at: ?string}
     */
    public static function columns(Table $table): array
    {
        $created_at = $updated_at = null;

        foreach ($table->columns as $name => $column) {
            if ('created_at' === $column->inflected_name) {
                $created_at ??= $name;
            } elseif ('updated_at' === $column->inflected_name) {
                $updated_at ??= $name;
            }
        }

        return ['created_at' => $created_at, 'updated_at' => $updated_at];
    }

    /**
     * The row of an INSERT, with the timestamps it lacks: created_at and updated_at, each set to
     * now() unless the row has a value for that column. A value the row has is the caller's, null
     * included. A suspended class, and a table without the column, get nothing.
     *
     * The keys of the row are column names or attribute names. The rest of the row is returned as
     * it was, and a column added is named by its own name (see columns()).
     *
     * A statement of several rows reads the clock once and gives that time as $now to each of them,
     * so that they do not straddle a second: without $now, every call reads now() itself.
     *
     * @param array<string, mixed> $row
     * @param string|null $now The time to fill in, as now() returns it. Null: now() is read
     * @return array<string, mixed>
     */
    public static function apply_to_insert(Table $table, string $class, array $row, ?string $now = null): array
    {
        return self::fill($table, $class, $row, true, $now);
    }

    /**
     * The SET of an UPDATE, with updated_at when it lacks one: set to now() unless the data has a
     * value for that column. created_at is never added, since only an INSERT stores it. A
     * suspended class, and a table without the column, get nothing. The keys are read, a column
     * added is named, and a given $now is taken, as apply_to_insert() does.
     *
     * @param array<string, mixed> $set
     * @param string|null $now The time to fill in, as now() returns it. Null: now() is read
     * @return array<string, mixed>
     */
    public static function apply_to_update(Table $table, string $class, array $set, ?string $now = null): array
    {
        return self::fill($table, $class, $set, false, $now);
    }

    /**
     * @param array<string, mixed> $data
     * @param bool $created_at Whether created_at is filled too: for an INSERT
     * @param string|null $now The time to fill in; null reads now(), and only when a column is missing
     * @return array<string, mixed>
     */
    private static function fill(Table $table, string $class, array $data, bool $created_at, ?string $now): array
    {
        if (self::suspended($class)) {
            return $data;
        }

        // the columns to fill, by the attribute name that identifies them: created_at first
        $columns = self::columns($table);
        $missing = [];

        if ($created_at && null !== $columns['created_at']) {
            $missing['created_at'] = $columns['created_at'];
        }

        if (null !== $columns['updated_at']) {
            $missing['updated_at'] = $columns['updated_at'];
        }

        // a key names the column of that name, or the one its attribute name stands for (#92). Where
        // the database matches names regardless of case (MySQL, SQLite) Table does not map an attribute
        // to a column that differs from it by case only ('updated_at' to 'UPDATED_AT'): the inflected
        // name finds that column. A column the data has must not be added again under its own name:
        // MySQL refuses an INSERT that names a column twice.
        if ([] !== $missing) {
            foreach (array_keys($data) as $key) {
                $key = (string) $key;
                $column = $table->columns[$table->real_column_name($key)] ?? $table->get_column_by_inflected_name($key);

                if (null !== $column) {
                    unset($missing[$column->inflected_name]);
                }
            }
        }

        if ([] !== $missing) {
            $now ??= self::now();

            foreach ($missing as $name) {
                $data[$name] = $now;
            }
        }

        return $data;
    }
}
