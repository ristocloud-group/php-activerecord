<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopedAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[GlobalScope]
    protected static function not_bush(Query $q): ?Query
    {
        return $q->where('author_id', '!=', 2);
    }
}
