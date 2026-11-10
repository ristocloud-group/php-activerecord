<?php

use ActiveRecord\Query;
use ActiveRecord\Scope;

class CollidingValidAndBadScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_fine(Query $q): ?Query
    {
        return $q;
    }

    public static function scope_where(Query $q): ?Query
    {
        return $q;
    }
}
