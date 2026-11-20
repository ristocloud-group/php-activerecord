<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopedTraitAuthor extends GlobalScopedAuthor
{
    use HidesTitoScope;

    #[GlobalScope]
    protected static function own(Query $q): ?Query
    {
        return $q->where('author_id', '<', 100);
    }
}
