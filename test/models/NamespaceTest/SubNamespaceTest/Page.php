<?php

namespace NamespaceTest\SubNamespaceTest;

class Page extends \ActiveRecord\Model
{
    public static $belongs_to = [
        ['book', 'class_name' => '\NamespaceTest\Book'],
    ];
}
