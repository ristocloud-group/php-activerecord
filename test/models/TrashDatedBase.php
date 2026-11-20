<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;

// SoftDeletable on an abstract parent, the trait on its subclass (TrashDatedChild): spec §3.2, a parent's interface counts
abstract class TrashDatedBase extends Model implements SoftDeletable
{
    public static $table_name = 'trash_dated';
}
