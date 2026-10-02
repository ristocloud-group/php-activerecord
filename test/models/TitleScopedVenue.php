<?php

// A has_many through whose hash condition key names a column of the middle table
// (events.title) unqualified: the database resolves it, so it is not checked
// against the target table (hosts).
class TitleScopedVenue extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $has_many = [
        ['events', 'foreign_key' => 'venue_id'],
        ['hosts', 'through' => 'events', 'foreign_key' => 'venue_id', 'conditions' => ['title' => 'Love Overboard']],
    ];
}
