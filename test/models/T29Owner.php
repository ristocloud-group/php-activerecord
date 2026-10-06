<?php

// Postgres reverse-FK through (owners -> mids -> tgts) whose target has a column named
// like the owner key but in another case ("Owner_Ref"); PDO lower-cases fetched names.
class T29Owner extends ActiveRecord\Model
{
    public static $table_name = 't29_owners';
    public static $has_many = [
        ['t29_mids', 'class_name' => 'T29Mid', 'foreign_key' => 'owner_ref'],
        ['t29_tgts', 'class_name' => 'T29Tgt', 'through' => 't29_mids', 'foreign_key' => 'owner_ref'],
    ];
}
