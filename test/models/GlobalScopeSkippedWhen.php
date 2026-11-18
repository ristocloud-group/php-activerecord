<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopeSkippedWhen extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function skipped(Query $q): ?Query
    {
        return $q->when(false, fn(Query $q) => $q->where('author_id', 1));
    }
}
