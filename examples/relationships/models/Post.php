<?php

/**
 * @property int    $id
 * @property int    $author_id
 * @property string $title
 * @property-read Author              $author
 * @property-read Author|null         $featured_author
 * @property-read array<int, Comment> $comments
 * @property-read array<int, Version> $own_versions
 * @property-read array<int, Tagging> $taggings
 * @property-read array<int, Tag>     $tags
 *
 * @method Comment create_comments(array<string, mixed> $attributes) has_many builder
 */
class Post extends ActiveRecord\Model
{
    public static $belongs_to = [
        ['author'],
        // the author, but only when featured; the OR is grouped with the key
        ['featured_author', 'class_name' => 'Author', 'foreign_key' => 'author_id', 'conditions' => ["name = 'Babbage' OR name = 'Byron'"]],
    ];

    public static $has_many = [
        ['comments'],
        // composite keys: versions of THIS post saved by ITS author, published only
        ['own_versions', 'class_name' => 'Version', 'foreign_key' => ['post_id', 'author_id'],
            'primary_key' => ['id', 'author_id'], 'conditions' => ['status = ?', 'published']],
        ['taggings'],                          // the intermediate assoc that `through` walks
        ['tags', 'through' => 'taggings'],
    ];
}
