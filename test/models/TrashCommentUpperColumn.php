<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

/**
 * trash_comments with its soft-delete column named in another case: the column deleted_at where the database matches
 * column names regardless of case (MySQL, SQLite), no column on Postgres.
 */
class TrashCommentUpperColumn extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $table_name = 'trash_comments';

    public static function soft_delete_column(): string
    {
        return 'DELETED_AT';
    }
}
