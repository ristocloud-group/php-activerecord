<?php

// has_many through books, the reverse-FK shape (books has_many the target): the declared conditions filter
// the target, so they name its aliases and attribute names (#144, #92): AliasedBookReview's score is rating,
// its reviewed_book is book_id (books has a book_id too); OddColumnAliased's space_name is "space name", and
// on Postgres its camelcase is "camelCase". A key they map is named with the target's table, which the
// middle table joins; an unchanged key (rating, and camelcase elsewhere than on Postgres) as declared.
class AliasConditionAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
    public static $has_many = [
        ['books', 'class_name' => 'AliasConditionBook', 'foreign_key' => 'author_id'],
        ['book_reviews', 'through' => 'books', 'class_name' => 'AliasedBookReview', 'foreign_key' => 'author_id',
            'order' => 'book_reviews.id asc', 'conditions' => ['score' => [4, 5]]],
        ['scored_reviews', 'through' => 'books', 'class_name' => 'AliasedBookReview', 'foreign_key' => 'author_id',
            'order' => 'book_reviews.id asc', 'conditions' => ['reviewed_book' => [1, 2], 'rating' => 4]],
        ['odd_columns', 'through' => 'books', 'class_name' => 'OddColumnAliased', 'foreign_key' => 'author_id',
            'order' => 'odd_columns.id asc', 'conditions' => ['space_name' => 'keep', 'camelcase' => 'x']],
    ];
}
