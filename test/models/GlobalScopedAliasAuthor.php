<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * A global scope that names a column by an alias attribute: handle is name.
 */
class GlobalScopedAliasAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
    public static $alias_attribute = ['handle' => 'name'];

    #[GlobalScope]
    protected static function not_tito(Query $q): ?Query
    {
        return $q->where('handle', '!=', 'Tito');
    }
}
