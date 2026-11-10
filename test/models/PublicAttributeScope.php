<?php

use ActiveRecord\Query;
use ActiveRecord\Scope;

class PublicAttributeScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[Scope]
    public static function x(Query $q): ?Query
    {
        return $q;
    }
}
