<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * A global scope carried by a trait that reads the queried model through static:: (its class and a static property).
 */
trait HidesNamedScope
{
    #[GlobalScope]
    protected static function not_named(Query $q): ?Query
    {
        GlobalScopeStaticBase::$seen[] = static::class . '::not_named';

        return $q->where('name', '!=', static::$hidden_name);
    }
}
