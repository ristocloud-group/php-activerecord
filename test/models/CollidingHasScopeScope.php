<?php

use ActiveRecord\Query;

class CollidingHasScopeScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_has_scope(Query $q): ?Query
    {
        return $q;
    }
}
