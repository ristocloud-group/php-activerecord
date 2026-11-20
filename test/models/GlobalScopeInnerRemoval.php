<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeInnerRemoval extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function inner(Query $q): ?Query
    {
        // the inner query removes this very scope: it runs unscoped, and counts every author
        return $q->where('author_id', '<=', static::without_global_scope('inner')->count());
    }
}
