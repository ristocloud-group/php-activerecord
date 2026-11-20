<?php

use ActiveRecord\Model;

/**
 * A model, not opted in, with a non-public method named like the before_restore callback: its Table does not load
 * (spec §7 row 4).
 */
class TrashPostPrivateCallback extends Model
{
    public static $table_name = 'trash_posts';

    protected function before_restore(): void {}
}
