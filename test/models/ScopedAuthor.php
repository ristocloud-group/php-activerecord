<?php

use ActiveRecord\Query;
use ActiveRecord\Scope;

class ScopedAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
    public static $has_many = [['books', 'class_name' => 'Book', 'foreign_key' => 'author_id']];

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

    public static function scope_empty_where(Query $q): ?Query
    {
        return $q->where([]);
    }

    public static function scope_skipped_when(Query $q): ?Query
    {
        return $q->when(false, fn(Query $q) => $q->where('name', 'Tito'));
    }

    public static function scope_joined_books(Query $q): ?Query
    {
        return $q->join('books', 'books.author_id', '=', 'authors.author_id')->select('authors.*');
    }

    public static function scope_with_books(Query $q): ?Query
    {
        return $q->with('books');
    }

    public static function scope_first_two(Query $q): ?Query
    {
        return $q->order_by('author_id')->limit(2);
    }

    public static function scope_throws_midway(Query $q): ?Query
    {
        $q->where('name', 'Tito')->or_where('author_id', 2);

        throw new RuntimeException('the scope failed midway');
    }

    public function scope_instance_only() {}

    public function call_scope_statically(): mixed
    {
        return static::tito_named();
    }

    // R4: inside the class a #[Scope] method is accessible, so PHP calls it itself, without the query
    public static function call_attribute_scope_from_static(): mixed
    {
        return static::named('Tito');
    }

    public function call_attribute_scope_from_instance(): mixed
    {
        return static::named('Tito');
    }
}
