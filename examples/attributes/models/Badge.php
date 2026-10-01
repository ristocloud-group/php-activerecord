<?php

/**
 * @property int|null $badge_no
 * @property int|null $id     the primary-key shortcut: reads and writes badge_no
 * @property string   $label
 */
class Badge extends ActiveRecord\Model
{
    // The primary key is not called `id`; `$badge->id` still reads it.
    public static $primary_key = 'badge_no';
}
