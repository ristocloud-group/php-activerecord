<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

// a soft-delete table without a primary key
class TrashPkless extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $table_name = 'trash_pkless';
}
