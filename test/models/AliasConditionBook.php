<?php

// The middle model of AliasConditionAuthor's has_many through: books has many book_reviews, and many
// odd_columns, joined on odd_columns.id = books.book_id
class AliasConditionBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $has_many = [
        ['book_reviews', 'class_name' => 'AliasedBookReview', 'foreign_key' => 'book_id'],
        ['scored_reviews', 'class_name' => 'AliasedBookReview', 'foreign_key' => 'book_id'],
        ['odd_columns', 'class_name' => 'OddColumnAliased', 'foreign_key' => 'id'],
    ];
}
