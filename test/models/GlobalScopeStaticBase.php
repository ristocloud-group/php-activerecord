<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * Global scopes that read the queried model through static:: (spec §3.1: scopes are inherited): its own not_hidden()
 * calls static::hidden_id(), and the trait's not_named() reads static::$hidden_name. This class hides authors 2 and
 * 4 (Uncle Bob); GlobalScopeStaticChild overrides both and hides 3 and 1 (Tito) instead.
 */
class GlobalScopeStaticBase extends ActiveRecord\Model
{
    use HidesNamedScope;

    public static $table_name = 'authors';
    public static $pk = 'author_id';

    /** @var list<string> the "<static::class>::<scope>" of every scope body run */
    public static array $seen = [];

    public static string $hidden_name = 'Uncle Bob';

    #[GlobalScope]
    protected static function not_hidden(Query $q): ?Query
    {
        self::$seen[] = static::class . '::not_hidden';

        return $q->where('author_id', '!=', static::hidden_id());
    }

    protected static function hidden_id(): int
    {
        return 2;
    }
}
