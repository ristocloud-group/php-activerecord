<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

class TrashTagging extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $belongs_to = ['trash_post', 'trash_tag'];
}
