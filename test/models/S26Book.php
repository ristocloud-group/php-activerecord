<?php

// books in schema s26, next to public.books: the sequence must be s26's own.
class S26Book extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $db = 's26';
}
