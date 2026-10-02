<?php

// GH #35: a relationship hash condition keyed by a table-qualified column.
// Of the two books, only book 1's author (Tito) satisfies it.
class QualifiedConditionBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $belongs_to = [['author', 'conditions' => ['authors.name' => 'Tito']]];
}
