<?php

/**
 * @property int    $id
 * @property int    $author_id
 * @property string $title
 * @property-read Author              $author
 * @property-read array<int, Comment> $comments
 * @property-read array<int, Tagging> $taggings
 * @property-read array<int, Tag>     $tags
 *
 * @method Comment create_comments(array<string, mixed> $attributes) has_many builder
 */
class Post extends ActiveRecord\Model
{
    /** @var array<int, array<int|string, mixed>> */
    public static $belongs_to = [['author']];

    /** @var array<int, array<int|string, mixed>> */
    public static $has_many = [
        ['comments'],
        ['taggings'],                          // the intermediate assoc that `through` walks
        ['tags', 'through' => 'taggings'],
    ];
}
