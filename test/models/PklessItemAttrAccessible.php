<?php

class PklessItemAttrAccessible extends ActiveRecord\Model
{
    public static $table_name = 'pkless_items';

    public static $attr_accessible = ['code'];
}
