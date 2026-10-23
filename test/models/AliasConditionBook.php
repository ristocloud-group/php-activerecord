<?php

// The middle model of AliasConditionAuthor's has_many through: books has many book_reviews
class AliasConditionBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $has_many = [['book_reviews', 'class_name' => 'AliasedBookReview', 'foreign_key' => 'book_id']];
}
