<?php

use ActiveRecord\Query;

class CollidingInheritedStaticScope extends ScopeStaticHelperBase
{
    public static function scope_helper(Query $q): ?Query
    {
        return $q;
    }
}
