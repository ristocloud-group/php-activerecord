<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

trait HidesTitoScope
{
    #[GlobalScope]
    protected static function not_tito(Query $q): ?Query
    {
        return $q->where('author_id', '!=', 1);
    }
}
