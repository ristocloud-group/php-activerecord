<?php

use ActiveRecord\SoftDeletable;

/**
 * trash_posts with 'unscoped' on the owner→middle relationships and on the :through targets, both shapes: the trashed
 * targets load, and the middle model's soft delete still filters the :through loads (spec §1.2 Q6, §3.5).
 */
class TrashPostUnscoped extends TrashPost
{
    public static $table_name = 'trash_posts';

    public static $has_many = [
        ['trash_comments', 'foreign_key' => 'trash_post_id', 'unscoped' => true],
        ['trash_taggings', 'foreign_key' => 'trash_post_id', 'unscoped' => [SoftDeletable::SCOPE]],
        ['trash_tags', 'through' => 'trash_taggings', 'foreign_key' => 'trash_post_id', 'unscoped' => [SoftDeletable::SCOPE]],
        ['trash_votes', 'through' => 'trash_comments', 'foreign_key' => 'trash_post_id', 'unscoped' => true],
    ];
}
