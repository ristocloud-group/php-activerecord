<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopePublic extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    public static function open(Query $q): ?Query
    {
        return $q;
    }
}
