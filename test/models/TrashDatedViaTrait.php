<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;

// SoftDeletes through another trait
class TrashDatedViaTrait extends Model implements SoftDeletable
{
    use TrashDatedSoftDeletes;

    public static $table_name = 'trash_dated';
}
