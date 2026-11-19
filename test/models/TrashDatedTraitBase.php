<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletes;

// the trait on an abstract parent, SoftDeletable on its subclass (TrashDatedInterfaceChild): spec §3.2, a parent's trait
// counts
abstract class TrashDatedTraitBase extends Model
{
    use SoftDeletes;

    public static $table_name = 'trash_dated';

    public static function soft_delete_column(): string
    {
        return 'removed_on';
    }
}
