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
