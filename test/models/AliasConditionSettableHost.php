<?php

// hosts, whose relationships a test declares at run time, before the table loads: a key quoted for the
// connection under test cannot be declared statically (#144)
class AliasConditionSettableHost extends ActiveRecord\Model
{
    public static $table_name = 'hosts';
    public static $has_many = [];
}
