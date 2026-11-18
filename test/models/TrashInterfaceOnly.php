<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;

// the interface without the trait (error 6)
class TrashInterfaceOnly extends Model implements SoftDeletable
{
    public static $table_name = 'trash_dated';

    public static function soft_delete_column(): string
    {
        return 'removed_on';
    }
}
