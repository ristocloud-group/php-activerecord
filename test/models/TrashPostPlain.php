<?php

use ActiveRecord\Model;

/**
 * trash_posts as a plain model: it has the deleted_at column but does not opt into soft delete.
 */
class TrashPostPlain extends Model
{
    public static $table_name = 'trash_posts';
}
