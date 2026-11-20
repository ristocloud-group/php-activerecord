<?php

use ActiveRecord\Query;

class GlobalScopeRedeclaredWithout extends GlobalScopedAuthor
{
    protected static function not_bush(Query $q): ?Query
    {
        return $q;
    }
}
