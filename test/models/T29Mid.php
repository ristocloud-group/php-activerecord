<?php

class T29Mid extends ActiveRecord\Model
{
    public static $table_name = 't29_mids';
    public static $has_many = [['t29_tgts', 'class_name' => 'T29Tgt', 'foreign_key' => 'mid_id']];
}
