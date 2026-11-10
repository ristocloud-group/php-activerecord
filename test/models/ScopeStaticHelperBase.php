<?php

class ScopeStaticHelperBase extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';

    public static function helper(): int
    {
        return 1;
    }
}
