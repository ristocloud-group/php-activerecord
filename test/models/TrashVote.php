<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

class TrashVote extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $belongs_to = ['trash_comment'];
}
