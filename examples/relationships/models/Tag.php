<?php

/**
 * @property int    $id
 * @property string $name
 * @property-read array<int, Tagging> $taggings
 * @property-read array<int, Post>    $posts
 */
class Tag extends ActiveRecord\Model
{
    public static $has_many = [
        ['taggings'],                          // intermediate assoc for `through`
        ['posts', 'through' => 'taggings'],
    ];
}
