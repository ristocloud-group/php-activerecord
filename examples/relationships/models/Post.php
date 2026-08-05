<?php

/**
 * @property int    $id
 * @property int    $author_id
 * @property string $title
 * @property-read Author              $author
 * @property-read array<int, Comment> $comments
 * @property-read array<int, Tagging> $taggings
 * @property-read array<int, Tag>     $tags
 */
class Post extends ActiveRecord\Model
{
    public static $belongs_to = [['author']];

    public static $has_many = [
        ['comments'],
        ['taggings'],                          // the intermediate assoc that `through` walks
        ['tags', 'through' => 'taggings'],
    ];
}
