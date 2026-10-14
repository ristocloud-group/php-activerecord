<?php

// a primary key attribute whose column is named differently: space_name is "space name" (#92)
class OddColumnBySpace extends ActiveRecord\Model
{
    public static $table_name = 'odd_columns';
    public static $primary_key = 'space_name';
}
