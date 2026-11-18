<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeReturnsOther extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function other(Query $q): ?Query
    {
        return new Query(static::class);
    }
}
