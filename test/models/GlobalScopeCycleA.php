<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeCycleA extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function to_b(Query $q): ?Query
    {
        return $q->where('author_id', '<=', GlobalScopeCycleB::new_query()->count());
    }
}
