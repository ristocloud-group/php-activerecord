<?php

/**
 * An author whose alias differs from its primary key column only in case (Author_Id, the column is
 * author_id): the same name where the database matches names regardless of case (MySQL, MariaDB,
 * SQLite), another one on Postgres, whose quoted names are case-sensitive (#144).
 */
class CaseAliasedPkAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $alias_attribute = ['Author_Id' => 'name'];
}
