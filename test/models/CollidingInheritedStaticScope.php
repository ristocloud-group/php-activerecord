<?php

use ActiveRecord\Query;
use ActiveRecord\Scope;

class CollidingInheritedStaticScope extends ScopeStaticHelperBase
{
    public static function scope_helper(Query $q): ?Query
    {
        return $q;
    }
}
