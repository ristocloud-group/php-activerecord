<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

// soft delete on a DATE column of another name
class TrashDated extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $table_name = 'trash_dated';

    public static function soft_delete_column(): string
    {
        return 'removed_on';
    }
}
