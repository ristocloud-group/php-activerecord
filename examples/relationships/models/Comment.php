<?php

/**
 * @property int    $id
 * @property int    $post_id
 * @property string $body
 * @property-read Post $post
 */
class Comment extends ActiveRecord\Model
{
    public static $belongs_to = [['post']];
}
