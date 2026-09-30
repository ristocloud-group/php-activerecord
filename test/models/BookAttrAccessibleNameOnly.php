<?php

// attr_accessible without the author_id foreign key (see AuthorWithGuardedBooks)
class BookAttrAccessibleNameOnly extends ActiveRecord\Model
{
    public static $pk = 'book_id';
    public static $table_name = 'books';

    public static $attr_accessible = ['name'];
}
