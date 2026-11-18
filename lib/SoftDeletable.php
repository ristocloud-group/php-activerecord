<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * A model with soft delete: a row is trashed by setting its soft-delete column, and every query of the model hides the
 * trashed rows through the global scope SCOPE. A model opts in by implementing this interface and using the
 * SoftDeletes trait, both required:
 * <code>
 * class Order extends Model implements SoftDeletable
 * {
 *     use SoftDeletes;
 * }
 * </code>
 * with_trashed(), only_trashed() and without_trashed() choose which rows a query reads.
 */
interface SoftDeletable
{
    /** The name of the global scope that hides the trashed rows. */
    public const string SCOPE = 'soft_deletes';

    /**
     * The column that holds when a row was trashed, null for a live row: a DATE or DATETIME column of the model's table.
     * It is read when a query is compiled, never when the table is loaded, so it may call static::table().
     */
    public static function soft_delete_column(): string;
}
