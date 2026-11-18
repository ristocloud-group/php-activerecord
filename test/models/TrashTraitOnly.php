<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletes;

// the trait without the interface (error 6)
class TrashTraitOnly extends Model
{
    use SoftDeletes;

    public static $table_name = 'trash_dated';

    public static function soft_delete_column(): string
    {
        return 'removed_on';
    }
}
