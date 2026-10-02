<?php

// The middle model of ThroughFkAuthor's reverse-FK has_many through: books has
// many awesome_people, joined on awesome_people.id = books.book_id.
class ThroughFkBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $has_many = [['awesome_people', 'class_name' => 'AwesomePerson', 'foreign_key' => 'id']];
}
