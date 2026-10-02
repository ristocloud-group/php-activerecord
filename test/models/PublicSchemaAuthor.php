<?php

// authors through an explicit schema ($db), with a declared primary key.
class PublicSchemaAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $db = 'public';
    public static $primary_key = 'author_id';
}
