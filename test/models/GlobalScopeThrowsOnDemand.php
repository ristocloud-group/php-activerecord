<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * A global scope whose body throws while $fail is set, and hides author 2 otherwise: a scope that threw must not stay
 * marked as running in its fiber.
 */
class GlobalScopeThrowsOnDemand extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static bool $fail = false;

    #[GlobalScope]
    protected static function fails(Query $q): ?Query
    {
        if (static::$fail) {
            throw new RuntimeException('the scope failed');
        }

        return $q->where('author_id', '!=', 2);
    }
}
