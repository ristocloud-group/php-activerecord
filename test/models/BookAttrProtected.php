<?php

class BookAttrProtected extends ActiveRecord\Model
{
    public static $pk = 'book_id';
    public static $table_name = 'books';

    public static $alias_attribute = [
        'name_alias' => 'name',
        'protected_pk_alias' => 'book_id',
        'secondary_author_alias' => 'secondary_author_id',
    ];
    public static $attr_protected = ['book_id', 'name'];
};
