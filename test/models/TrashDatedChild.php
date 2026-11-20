<?php

use ActiveRecord\SoftDeletes;

// the trait here, SoftDeletable on its parent
class TrashDatedChild extends TrashDatedBase
{
    use SoftDeletes;

    public static function soft_delete_column(): string
    {
        return 'removed_on';
    }
}
