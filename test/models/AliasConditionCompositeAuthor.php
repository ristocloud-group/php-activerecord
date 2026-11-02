<?php

// has_many with composite keys whose declared hash conditions name AliasedCompositeItem's alias (#144): label
// is title. The key binds follow the declared ones, one per placeholder (#116), also after an alias and its
// column's own name, both kept (#129).
class AliasConditionCompositeAuthor extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [
        ['items', 'class_name' => 'AliasedCompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
            'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc',
            'conditions' => ['label' => ['keep', 'mirror', 'other']]],
        ['labelled_items', 'class_name' => 'AliasedCompositeItem', 'foreign_key' => ['author_ref', 'parent_ref'],
            'primary_key' => ['author_id', 'parent_author_id'], 'order' => 'id asc',
            'conditions' => ['label' => ['keep', 'mirror', 'x'], 'title' => ['x', 'keep']]],
    ];
}
