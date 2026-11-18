<?php

/**
 * A GlobalScopeStaticBase that overrides what its inherited global scopes read through static::: they hide authors 3
 * and 1 (Tito) for it.
 */
class GlobalScopeStaticChild extends GlobalScopeStaticBase
{
    public static string $hidden_name = 'Tito';

    protected static function hidden_id(): int
    {
        return 3;
    }
}
