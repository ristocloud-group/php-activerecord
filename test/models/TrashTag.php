<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

class TrashTag extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $has_many = ['trash_taggings'];
}
