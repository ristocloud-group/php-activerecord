<?php

use ActiveRecord\Query;
use ActiveRecord\Scope;

class ScopedAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function scope_tito_or_parent(Query $q, int $parent): ?Query
    {
        return $q->where('parent_author_id', $parent)->or_where('name', 'Tito');
    }

    #[Scope]
    protected static function named(Query $q, string $name): ?Query
    {
        return $q->where('name', $name);
    }

    public static function scope_newest_first(Query $q): ?Query
    {
        return $q->order_by_desc('author_id');
    }

    public static function scope_noop(Query $q): ?Query
    {
        return null;
    }

    public static function scope_rogue(Query $q): ?Query
    {
        return static::new_query();
    }

    public static function scope_untyped_bad($q)
    {
        return 'x';
    }

    public static function scope_tito_named(Query $q): ?Query
    {
        return $q->named('Tito');
    }

    public function scope_instance_only() {}
}
