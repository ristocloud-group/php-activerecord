<?php

// books through an explicit schema ($db), without a declared primary key: the
// key must be inferred from the introspected columns, as without `$db`.
class PublicSchemaBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $db = 'public';
}
