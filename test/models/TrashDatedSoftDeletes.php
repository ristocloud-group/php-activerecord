<?php

use ActiveRecord\SoftDeletes;

// a consumer trait that uses SoftDeletes: spec §3.2, the trait counts through another trait
trait TrashDatedSoftDeletes
{
    use SoftDeletes;

    public static function soft_delete_column(): string
    {
        return 'removed_on';
    }
}
