<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopedAuthorChild extends GlobalScopedAuthor
{
    #[GlobalScope]
    protected static function named(Query $q): ?Query
    {
        return $q->where_not_null('name');
    }

    #[GlobalScope]
    protected static function not_bush(Query $q): ?Query
    {
        return $q->where('author_id', '!=', 2);
    }
}
