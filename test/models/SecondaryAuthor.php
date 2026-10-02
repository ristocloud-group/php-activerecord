<?php

// The target of CoauthoredAuthor's has_many through: authors joined on
// books.secondary_author_id (the name keyify() gives this class).
class SecondaryAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
}
