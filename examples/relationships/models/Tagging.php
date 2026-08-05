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
    public static $belongs_to = [['post'], ['tag']];
}
