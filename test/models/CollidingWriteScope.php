<?php

use ActiveRecord\Query;

class CollidingWriteScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_delete(Query $q): ?Query
    {
        return $q;
    }
}
