<?php

/**
 * An Author whose connection() override returns another connection than its table's while a test
 * sets one, as a consumer's base class can route its writes: update_all() and delete_all() run on
 * what static::connection() returns.
 */
class RoutedAuthor extends Author
{
    public static $table_name = 'authors';

    /** The connection connection() returns instead of the table's; null: the table's */
    public static ?ActiveRecord\Connection $route = null;

    public static function connection(): ActiveRecord\Connection
    {
        return self::$route ?? parent::connection();
    }
}
