<?php

// an alias of an attribute whose column is named differently: nick -> space_name -> "space name" (#92)
class OddColumnAliased extends ActiveRecord\Model
{
    public static $table_name = 'odd_columns';
    public static $alias_attribute = ['nick' => 'space_name'];
}
