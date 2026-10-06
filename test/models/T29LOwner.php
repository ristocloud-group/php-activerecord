<?php

// Postgres reverse-FK through whose owner key is 54 bytes long and also a target column:
// the private alias must stay within Postgres' 63-byte identifier limit.
class T29LOwner extends ActiveRecord\Model
{
    public static $table_name = 't29_owners';
    public static $has_many = [
        ['t29_lmids', 'class_name' => 'T29LMid', 'foreign_key' => 'owner_reference_column_with_a_deliberately_long_name_x'],
        ['t29_ltgts', 'class_name' => 'T29LTgt', 'through' => 't29_lmids', 'foreign_key' => 'owner_reference_column_with_a_deliberately_long_name_x'],
    ];
}
