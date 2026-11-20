<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * A global scope named like a public Query method (spec §6, error 3).
 */
class GlobalScopeQueryMethod extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function latest(Query $q): ?Query
    {
        return $q;
    }
}
