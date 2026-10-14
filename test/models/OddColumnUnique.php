<?php

// validates_uniqueness_of on an attribute whose column is named differently (#92)
class OddColumnUnique extends ActiveRecord\Model
{
    public static $table_name = 'odd_columns';

    public static $validates_uniqueness_of = [
        ['space_name'],
    ];
}
