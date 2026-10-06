<?php

// A reverse-FK has_many / has_one through (authors -> books -> awesome_people)
// whose target table also has a column named like the owner key (author_id): the
// owner key must be books.author_id, never the ambiguous author_id.
class ThroughFkAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
    public static $has_many = [
        ['through_fk_books', 'class_name' => 'ThroughFkBook', 'foreign_key' => 'author_id'],
        ['awesome_people', 'through' => 'through_fk_books', 'foreign_key' => 'author_id'],
    ];
    public static $has_one = [
        ['awesome_person', 'through' => 'through_fk_books', 'foreign_key' => 'author_id', 'conditions' => ['name' => 'Ancient Art of Main Tanking']],
    ];
}
