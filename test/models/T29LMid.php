<?php

class T29LMid extends ActiveRecord\Model
{
    public static $table_name = 't29_lmids';
    public static $has_many = [['t29_ltgts', 'class_name' => 'T29LTgt', 'foreign_key' => 'lmid_id']];
}
