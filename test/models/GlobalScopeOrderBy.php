<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeOrderBy extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function ordered(Query $q): ?Query
    {
        return $q->where('author_id', '!=', 2)->order_by('name');
    }
}
