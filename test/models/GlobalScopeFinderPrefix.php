<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * A global scope named like a dynamic finder (spec §6, error 3).
 */
class GlobalScopeFinderPrefix extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function find_by_hidden(Query $q): ?Query
    {
        return $q;
    }
}
