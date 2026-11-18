<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * A GlobalScopedAuthor that validates its name as unique: the name of the author the scope hides is taken too.
 */
class GlobalScopedUniqueAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
    public static $validates_uniqueness_of = [['name']];

    #[GlobalScope]
    protected static function not_bush(Query $q): ?Query
    {
        return $q->where('author_id', '!=', 2);
    }
}
