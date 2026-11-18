<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeCycleB extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function to_a(Query $q): ?Query
    {
        return $q->where('author_id', '<=', GlobalScopeCycleA::new_query()->count());
    }
}
