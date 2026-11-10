<?php

use ActiveRecord\Query;
use ActiveRecord\Scope;

class CollidingFinderPrefixScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_find_by_title(Query $q): ?Query
    {
        return $q;
    }
}
