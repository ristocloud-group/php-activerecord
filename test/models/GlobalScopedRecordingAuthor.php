<?php

/**
 * A GlobalScopedAuthor (author 2 hidden by not_bush) whose find() and first() overrides record their calls: a
 * relationship load without 'unscoped' goes through them, one with 'unscoped' does not (spec §3.5, §4.5).
 *
 * Fixture parents: 1 -> 3, 2 -> 2, 3 -> 1, 4 -> 2.
 */
class GlobalScopedRecordingAuthor extends GlobalScopedAuthor
{
    /** @var list<string> */
    public static array $calls = [];

    public static $belongs_to = [
        ['parent', 'class_name' => 'GlobalScopedRecordingAuthor', 'foreign_key' => 'parent_author_id'],
        ['any_parent', 'class_name' => 'GlobalScopedRecordingAuthor', 'foreign_key' => 'parent_author_id', 'unscoped' => true],
    ];

    public static $has_many = [
        ['children', 'class_name' => 'GlobalScopedRecordingAuthor', 'foreign_key' => 'parent_author_id', 'order' => 'author_id'],
        ['any_children', 'class_name' => 'GlobalScopedRecordingAuthor', 'foreign_key' => 'parent_author_id', 'order' => 'author_id',
            'unscoped' => ['not_bush']],
    ];

    public static $has_one = [
        ['second_child', 'class_name' => 'GlobalScopedRecordingAuthor', 'foreign_key' => 'parent_author_id',
            'order' => 'author_id desc', 'offset' => 1],
        ['any_second_child', 'class_name' => 'GlobalScopedRecordingAuthor', 'foreign_key' => 'parent_author_id',
            'order' => 'author_id desc', 'offset' => 1, 'unscoped' => true],
    ];

    public static function find(/* $type, $options */)
    {
        $args = func_get_args();
        static::$calls[] = 'find ' . (is_string($args[0] ?? null) ? $args[0] : 'pk');

        return parent::find(...$args);
    }

    public static function first(/* ... */)
    {
        static::$calls[] = 'first';

        return parent::first(...func_get_args());
    }
}
