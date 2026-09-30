<?php

namespace NamespaceTest;

class Author extends \ActiveRecord\Model
{
    public static $pk = 'author_id';

    public static $has_many = [
        ['books', 'class_name' => '\NamespaceTest\Book'],
    ];
}
