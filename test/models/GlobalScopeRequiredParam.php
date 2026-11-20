<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeRequiredParam extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function needs(Query $q, int $x): ?Query
    {
        return $q;
    }
}
