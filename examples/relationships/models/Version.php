<?php

/**
 * A saved version of a post; `author_id` is whoever saved it (null for a guest).
 *
 * @property int      $id
 * @property int      $post_id
 * @property int|null $author_id
 * @property string   $body
 * @property string   $status
 */
class Version extends ActiveRecord\Model {}
