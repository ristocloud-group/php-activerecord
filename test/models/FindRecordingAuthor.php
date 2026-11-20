<?php

/**
 * A model without global scopes whose find() and exists() record their calls: reload() and the
 * uniqueness validation must still go through them.
 */
class FindRecordingAuthor extends Author
{
    public static $table_name = 'authors';
    public static $validates_uniqueness_of = [['name']];
    public static int $finds = 0;
    public static int $exists_calls = 0;

    public static function find(/* ... */)
    {
        ++self::$finds;

        return parent::find(...func_get_args());
    }

    public static function exists(/* ... */)
    {
        ++self::$exists_calls;

        return parent::exists(...func_get_args());
    }
}
