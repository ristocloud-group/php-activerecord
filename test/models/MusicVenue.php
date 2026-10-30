<?php

// venues, with a has_many whose declared conditions name a plain column of the target (events.type): the
// eager load of a has_many with declared conditions in the golden corpus (GoldenSqlTest)
class MusicVenue extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [
        ['music_events', 'class_name' => 'Event', 'foreign_key' => 'venue_id', 'order' => 'id asc', 'conditions' => ['type' => 'Music']],
    ];
}
