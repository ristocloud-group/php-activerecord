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
     * @throws ConfigException when soft_delete_column() names no DATE or DATETIME column of the table
     */
    public function trashed()
    {
        // the attribute of the column as the table names it, whatever the case soft_delete_column() gives
        $table = static::table();

        return null !== $this->{$table->columns[ScopeRegistry::soft_delete_column($table)]->inflected_name};
    }

    /**
     * Takes this record out of the trash: an UPDATE by primary key sets its soft-delete column to NULL, and updated_at
     * to now when the table has it, between the before_restore and after_restore callbacks. No validation and no save
     * callback run, and the record's other pending changes are neither written nor discarded. A record that is not
     * trashed is restored too.
     *
     * <code>
     * $order = Order::only_trashed()->find($id);
     * $order->restore();
     * </code>
     *
     * @return bool true, or false when a before_restore callback returned false
     * @throws ActiveRecordException on a record without a primary key value, with a null or changed one, or that
     *   force_delete() removed
     * @throws ReadOnlyException on a readonly record
     * @throws ConfigException when the soft delete is declared wrongly (spec §6)
     */
    public function restore()
    {
        return $this->perform_restore_on_model();
    }

    /**
     * DELETEs this record for real, trashed or live: delete() on its hard path, with the destroy callbacks, during
     * which is_force_deleting() is true. delete() trashes it instead. An override of delete() runs, and what it returns
     * is returned.
     *
     * @return mixed what delete() returns: true, or false when a before_destroy callback returned false
     * @throws ActiveRecordException|ConfigException as delete() does
     */
    public function force_delete()
    {
        return $this->perform_force_delete_on_model();
    }

    /**
     * Whether force_delete() is running on this record, its callbacks included: a delete() override or a destroy
     * callback can tell a hard delete from a soft one.
     */
    public function is_force_deleting(): bool
    {
        return $this->force_deleting_flag();
    }
}
