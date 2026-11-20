<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

/**
 * trash_posts with the destroy and restore callbacks, which record what they see in $log: each entry is the callback's
 * name, whether the record was trashed then and, for the destroy callbacks, is_force_deleting(). before_restore cancels
 * the restore while $cancel_restore is set; before_destroy cancels the delete while $cancel_destroy is set and throws
 * while $throw_on_destroy is set.
 */
class TrashPostWithCallbacks extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $table_name = 'trash_posts';

    /** @var list<string> */
    public static array $log = [];

    public static bool $cancel_restore = false;

    public static bool $cancel_destroy = false;

    public static bool $throw_on_destroy = false;

    public static function reset(): void
    {
        self::$log = [];
        self::$cancel_restore = false;
        self::$cancel_destroy = false;
        self::$throw_on_destroy = false;
    }

    public function before_destroy()
    {
        self::$log[] = 'before_destroy ' . var_export($this->trashed(), true) . ' force ' . var_export($this->is_force_deleting(), true);

        if (self::$throw_on_destroy) {
            throw new RuntimeException('before_destroy failed');
        }

        return self::$cancel_destroy ? false : null;
    }

    public function after_destroy()
    {
        self::$log[] = 'after_destroy ' . var_export($this->trashed(), true) . ' force ' . var_export($this->is_force_deleting(), true);
    }

    public function before_restore()
    {
        self::$log[] = 'before_restore ' . var_export($this->trashed(), true);

        return self::$cancel_restore ? false : null;
    }

    public function after_restore()
    {
        self::$log[] = 'after_restore ' . var_export($this->trashed(), true);
    }
}
