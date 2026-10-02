<?php

// A relationship hash condition whose key names no column of the target table
// (authors): rejected before the query, on lazy and on eager load.
class UnknownKeyConditionBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $belongs_to = [['author', 'conditions' => ['nope' => 1]]];
}
