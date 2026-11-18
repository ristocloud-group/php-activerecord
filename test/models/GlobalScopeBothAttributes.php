<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;
use ActiveRecord\Scope;

class GlobalScopeBothAttributes extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[Scope]
    #[GlobalScope]
    protected static function both(Query $q): ?Query
    {
        return $q;
    }
}
