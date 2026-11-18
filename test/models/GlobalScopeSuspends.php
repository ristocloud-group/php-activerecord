<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeSuspends extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function suspends(Query $q): ?Query
    {
        // inside a fiber, the body pauses while it runs: the scope stays marked for that fiber only
        if (null !== Fiber::getCurrent()) {
            Fiber::suspend();
        }

        return $q->where('author_id', '!=', 2);
    }
}
