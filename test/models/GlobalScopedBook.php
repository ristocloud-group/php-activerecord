<?php

/**
 * A book whose author is a GlobalScopedAuthor (author 2 hidden by not_bush): 'author' keeps the target's scope,
 * 'any_author' lifts it, 'nope_author' names a scope the target does not have (error 12 at its first load).
 */
class GlobalScopedBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $pk = 'book_id';
    public static $belongs_to = [
        ['author', 'class_name' => 'GlobalScopedAuthor', 'foreign_key' => 'author_id'],
        ['any_author', 'class_name' => 'GlobalScopedAuthor', 'foreign_key' => 'author_id', 'unscoped' => true],
        ['nope_author', 'class_name' => 'GlobalScopedAuthor', 'foreign_key' => 'author_id', 'unscoped' => ['nope']],
    ];
}
