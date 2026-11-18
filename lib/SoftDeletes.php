<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The soft delete of a model that implements SoftDeletable (see there): the column, deleted_at unless the model
 * overrides soft_delete_column(), and the global scope that hides the trashed rows.
 */
trait SoftDeletes
{
    /**
     * The column that holds when a row was trashed: deleted_at. Override it to name another DATE or DATETIME column.
     */
    public static function soft_delete_column(): string
    {
        return 'deleted_at';
    }

    /**
     * Hides the trashed rows. Governed by with_trashed(), only_trashed() and without_trashed() only.
     *
     * @param Query<Model> $q
     * @return Query<Model>
     */
    #[GlobalScope]
    protected static function soft_deletes(Query $q): ?Query
    {
        return $q->where_null(static::soft_delete_column());
    }

    /**
     * DELETEs the records of $options for real, trashed and live, and returns how many. It takes delete_all()'s options
     * and checks (conditions, order, limit; a bare conditions hash or string; nothing for every record), and keeps every
     * other global scope of the model. delete_all() trashes them instead.
     *
     * <code>
     * Order::force_delete_all(['conditions' => ['deleted_at < ?', $cutoff]]);
     * </code>
     *
     * @param mixed $options as delete_all() takes them
     * @return int Number of rows deleted
     * @throws ActiveRecordException|ConfigException as delete_all() does
     */
    public static function force_delete_all($options = []): int
    {
        return static::perform_force_delete_all($options);
    }

    /**
     * Whether this record is trashed: its soft-delete column holds a value. A record read without the column throws
     * UndefinedPropertyException.
     *
     * @return bool
     * @throws UndefinedPropertyException when the column was not read
     */
    public function trashed()
    {
        return null !== $this->{static::soft_delete_column()};
    }
}
