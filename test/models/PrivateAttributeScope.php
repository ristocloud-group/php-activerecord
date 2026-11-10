<?php

use ActiveRecord\Query;
use ActiveRecord\Scope;

class PrivateAttributeScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[Scope]
    private static function x(Query $q): ?Query
    {
        return $q;
    }
}
