<?php

/**
 * @property int    $id
 * @property string $name
 * @property string $country
 */
class Company extends ActiveRecord\Model
{
    /** @var array<int, array<int|string, mixed>> */
    public static $has_many = [['members']];
}
