<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

// a soft_delete_column() that reads static::table() (spec §3.2: the column is read lazily, never in Table::__construct)
class TrashDatedTableColumn extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $table_name = 'trash_dated';

    public static function soft_delete_column(): string
    {
        return isset(static::table()->columns['removed_on']) ? 'removed_on' : 'deleted_at';
    }
}
