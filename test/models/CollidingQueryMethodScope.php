<?php

use ActiveRecord\Query;

class CollidingQueryMethodScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_where(Query $q): ?Query
    {
        return $q;
    }
}
