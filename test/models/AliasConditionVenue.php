<?php

// A has_many and a has_one whose declared hash conditions name AliasedEvent's aliases (#144): kind is
// type, headline is title. An alias next to its column's own name keeps both conditions, ANDed (#129).
class AliasConditionVenue extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [
        ['music_events', 'class_name' => 'AliasedEvent', 'foreign_key' => 'venue_id', 'order' => 'id asc',
            'conditions' => ['kind' => 'Music']],
        ['music_events_twice', 'class_name' => 'AliasedEvent', 'foreign_key' => 'venue_id', 'order' => 'id asc',
            'conditions' => ['kind' => 'Music', 'type' => 'Music']],
        ['contradicted_events', 'class_name' => 'AliasedEvent', 'foreign_key' => 'venue_id', 'order' => 'id asc',
            'conditions' => ['kind' => 'Music', 'type' => 'Blah']],
    ];
    public static $has_one = [
        ['headline_event', 'class_name' => 'AliasedEvent', 'foreign_key' => 'venue_id', 'order' => 'id asc',
            'conditions' => ['headline' => ['Love Overboard', 'Blah']]],
    ];
}
