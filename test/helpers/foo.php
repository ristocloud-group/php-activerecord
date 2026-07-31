<?php

namespace foo\bar\biz;

class User extends \ActiveRecord\Model
{
    public static $has_many = [
        ['user_newsletters'],
        ['newsletters', 'through' => 'user_newsletters'],
    ];

}

class Newsletter extends \ActiveRecord\Model
{
    public static $has_many = [
        ['user_newsletters'],
        ['users', 'through' => 'user_newsletters'],
    ];
}

class UserNewsletter extends \ActiveRecord\Model
{
    public static $belong_to = [
        ['user'],
        ['newsletter'],
    ];
}

class Story extends \ActiveRecord\Model
{
    public static $has_many = [
        ['read_receipts', 'class_name' => 'NewsReadReceipt'],
        ['readers', 'class_name' => 'User', 'through' => 'read_receipts'],
    ];
}

class NewsReadReceipt extends \ActiveRecord\Model
{
    public static $belongs_to = [
        ['story'],
        ['user'],
    ];
}
