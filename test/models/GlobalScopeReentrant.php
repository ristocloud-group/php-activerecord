<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeReentrant extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function again(Query $q): ?Query
    {
        return $q->where('author_id', '<=', static::new_query()->count());
    }
}
