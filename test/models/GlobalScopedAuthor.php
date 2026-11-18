<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

class GlobalScopedAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
    public static $has_many = [['books', 'class_name' => 'GlobalScopedBook', 'foreign_key' => 'author_id', 'order' => 'book_id']];

    #[GlobalScope]
    protected static function not_bush(Query $q): ?Query
    {
        return $q->where('author_id', '!=', 2);
    }
}
