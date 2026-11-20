<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Model;
use ActiveRecord\Query;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

class TrashPost extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $has_many = [
        'trash_comments',
        'trash_taggings',
        ['trash_tags', 'through' => 'trash_taggings'],
        ['trash_votes', 'through' => 'trash_comments'],
    ];

    public static $validates_uniqueness_of = [['code']];

    #[GlobalScope]
    protected static function published(Query $q): ?Query
    {
        return $q->where('draft', 0);
    }
}
