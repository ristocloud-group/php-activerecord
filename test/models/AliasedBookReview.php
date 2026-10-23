<?php

// book_reviews, with an alias of its rating: the target of AliasConditionAuthor's has_many through (#144)
class AliasedBookReview extends ActiveRecord\Model
{
    public static $table_name = 'book_reviews';
    public static $alias_attribute = ['score' => 'rating'];
}
