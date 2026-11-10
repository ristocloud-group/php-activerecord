<?php

use ActiveRecord\Query;
use ActiveRecord\Scope;

class InstanceAttributeScope extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    #[Scope]
    public function x(Query $q): ?Query
    {
        return $q;
    }
}
