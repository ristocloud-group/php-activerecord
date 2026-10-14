<?php

// validates_uniqueness_of on a model whose primary key column is named differently:
// the pk attribute space_name is the column "space name" (#92)
class OddColumnUniqueBySpace extends ActiveRecord\Model
{
    public static $table_name = 'odd_columns';
    public static $primary_key = 'space_name';

    public static $validates_uniqueness_of = [
        ['dash_name'],
    ];
}
