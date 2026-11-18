<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * A global scope named update (spec §6, error 3): refused as a public method of Query, a rule checked before update/delete.
 */
class GlobalScopeUpdate extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function update(Query $q): ?Query
    {
        return $q;
    }
}
