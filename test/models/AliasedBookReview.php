<?php

// book_reviews, with aliases of its rating and of its book_id, a column the middle table books has too: the
// target of AliasConditionAuthor's has_many through (#144)
class AliasedBookReview extends ActiveRecord\Model
{
    public static $table_name = 'book_reviews';
    public static $alias_attribute = ['score' => 'rating', 'reviewed_book' => 'book_id'];
}
