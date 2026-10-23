<?php

// has_many through books, the reverse-FK shape (books has_many book_reviews): the declared conditions
// filter the target, AliasedBookReview, so they name its aliases (#144): score is rating
class AliasConditionAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
    public static $has_many = [
        ['books', 'class_name' => 'AliasConditionBook', 'foreign_key' => 'author_id'],
        ['book_reviews', 'through' => 'books', 'class_name' => 'AliasedBookReview', 'foreign_key' => 'author_id',
            'order' => 'book_reviews.id asc', 'conditions' => ['score' => [4, 5]]],
    ];
}
