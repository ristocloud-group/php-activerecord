<?php

use ActiveRecord\Query;

/**
 * scope_scope_x() declares the scope scope_x, whose name is the method of the scope x: ::scope_x() would run that
 * method itself.
 */
class CollidingScopeMethodScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_x(Query $q): ?Query
    {
        return $q;
    }

    public static function scope_scope_x(Query $q): ?Query
    {
        return $q;
    }
}
