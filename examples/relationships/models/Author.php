<?php

/**
 * @property int    $id
 * @property string $name
 * @property-read array<int, Post> $posts
 * @property-read Profile          $profile
 */
class Author extends ActiveRecord\Model
{
    // Note: this fork's `has_many ... through` only supports the join-table shape
    // (the through model `belongs_to` both sides, like Tagging below) — not a plain
    // one-to-many chain such as "comments through posts" (Comment belongs_to Post,
    // not the reverse), so that association is intentionally not declared here.
    public static $has_many = [
        ['posts'],
    ];

    public static $has_one = [
        ['profile'],
    ];
}
