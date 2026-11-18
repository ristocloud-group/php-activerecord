<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * A pkless_items row (no primary key) with a global scope: reload() has no key to name its row by.
 */
class GlobalScopedPklessItem extends ActiveRecord\Model
{
    public static $table_name = 'pkless_items';

    #[GlobalScope]
    protected static function not_ninety_nine(Query $q): ?Query
    {
        return $q->where('code', '!=', 99);
    }
}
