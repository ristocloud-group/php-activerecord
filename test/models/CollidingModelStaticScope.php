<?php

use ActiveRecord\Query;

class CollidingModelStaticScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_create(Query $q): ?Query
    {
        return $q;
    }
}
