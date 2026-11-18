<?php

/**
 * A book whose has_many on the scoped GlobalScopedAuthor declares conditions with a top-level OR (Review Focus 3): the
 * declared OR must stay inside its group, ANDed with the key, and the target's scope outside it.
 */
class GlobalScopedBookAuthorOr extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $pk = 'book_id';
    public static $has_many = [
        ['listed_authors', 'class_name' => 'GlobalScopedAuthor', 'foreign_key' => 'author_id', 'primary_key' => 'author_id',
            'conditions' => "name = 'Tito' OR name = 'George W. Bush'", 'order' => 'author_id'],
    ];
}
