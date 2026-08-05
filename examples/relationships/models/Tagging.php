<?php

/**
 * @property int $id
 * @property int $post_id
 * @property int $tag_id
 * @property-read Post $post
 * @property-read Tag  $tag
 */
class Tagging extends ActiveRecord\Model
{
    /** @var array<int, array<int|string, mixed>> */
    public static $belongs_to = [['post'], ['tag']];
}
