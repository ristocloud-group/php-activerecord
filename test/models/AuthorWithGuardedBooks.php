<?php

class AuthorWithGuardedBooks extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';

    public static $has_many = [
        ['books', 'class_name' => 'BookAttrAccessibleNameOnly', 'foreign_key' => 'author_id'],
    ];
}
