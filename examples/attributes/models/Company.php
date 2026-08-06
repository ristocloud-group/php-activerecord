<?php

/**
 * @property int    $id
 * @property string $name
 * @property string $country
 */
class Company extends ActiveRecord\Model
{
    public static $has_many = [['members']];
}
