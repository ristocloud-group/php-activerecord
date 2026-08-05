<?php

/**
 * @property int    $id
 * @property int    $author_id
 * @property string $bio
 * @property-read Author $author
 */
class Profile extends ActiveRecord\Model
{
    public static $belongs_to = [['author']];
}
