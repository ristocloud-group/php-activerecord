<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeLikeLocal extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function tito(Query $q): ?Query
    {
        return $q;
    }

    public static function scope_tito(Query $q): ?Query
    {
        return $q;
    }
}
