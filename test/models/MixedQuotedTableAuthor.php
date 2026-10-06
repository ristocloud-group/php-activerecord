<?php

// authors through a partly quoted, db-qualified $table_name (`db`.authors), set per
// adapter by the test: quoted part by part, as `db`.`authors`.
class MixedQuotedTableAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $primary_key = 'author_id';
}
