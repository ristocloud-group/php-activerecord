<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

/**
 * trash_posts with a delete() override that calls parent::delete() and returns nothing; it records is_force_deleting()
 * in $seen each time it runs.
 */
class TrashPostDeleteOverride extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $table_name = 'trash_posts';

    /** @var list<bool> */
    public static array $seen = [];

    public function delete()
    {
        self::$seen[] = $this->is_force_deleting();
        parent::delete();
    }
}
