<?php

use ActiveRecord\Query;

/**
 * A scope named like a public instance method of Model: CollidingInstanceMethodScope::reload() is PHP's "Non-static
 * method ... cannot be called statically", never the scope.
 */
class CollidingInstanceMethodScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_reload(Query $q): ?Query
    {
        return $q;
    }
}
