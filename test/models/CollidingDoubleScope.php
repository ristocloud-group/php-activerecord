<?php

use ActiveRecord\Query;
use ActiveRecord\Scope;

class CollidingDoubleScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_twice(Query $q): ?Query
    {
        return $q;
    }

    #[Scope]
    protected static function twice(Query $q): ?Query
    {
        return $q;
    }
}
