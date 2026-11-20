<?php

/**
 * trash_posts with declared conditions, each with an OR, on both :through shapes: the join-table one (trash_tags) and
 * the has_many→has_many chain (trash_votes). The middle model's soft delete joins them as one more AND term.
 */
class TrashPostConditioned extends TrashPost
{
    public static $table_name = 'trash_posts';

    public static $has_many = [
        ['trash_comments', 'foreign_key' => 'trash_post_id'],
        ['trash_taggings', 'foreign_key' => 'trash_post_id'],
        ['trash_tags', 'through' => 'trash_taggings', 'foreign_key' => 'trash_post_id', 'conditions' => ["name = 'ruby' OR name = 'php'"]],
        ['trash_votes', 'through' => 'trash_comments', 'foreign_key' => 'trash_post_id', 'conditions' => ['value = 5 OR value = 3']],
    ];
}
