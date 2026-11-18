<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

class TrashComment extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $belongs_to = [
        'trash_post',
        ['any_post', 'class_name' => 'TrashPost', 'foreign_key' => 'trash_post_id', 'unscoped' => [SoftDeletable::SCOPE]],
    ];

    public static $has_many = ['trash_votes'];
}
