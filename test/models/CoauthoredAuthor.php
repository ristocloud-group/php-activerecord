<?php

// A belongs_to-shaped (historical) has_many through: authors -> books -> authors
// (books.secondary_author_id). The owner key books.author_id is also a column of
// the target table (authors.author_id).
class CoauthoredAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
    public static $has_many = [
        ['books', 'class_name' => 'Book', 'foreign_key' => 'author_id'],
        ['secondary_authors', 'class_name' => 'SecondaryAuthor', 'through' => 'books', 'foreign_key' => 'author_id'],
    ];
}
