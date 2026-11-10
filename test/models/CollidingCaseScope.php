<?php

use ActiveRecord\Query;

/**
 * A scope named like a Query method in another case: PHP's method names are case-insensitive, so $query->Where() runs
 * Query::where(), and the scope could never be reached there.
 */
class CollidingCaseScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_Where(Query $q): ?Query
    {
        return $q;
    }
}
