<?php

// belongs_to Venue, whose declared hash conditions name Venue's aliases (#144): mycity is city, marquee
// is name. An alias next to its column's own name keeps both conditions, ANDed (#129). plain_venue names
// columns only: its SQL is the one it always had.
class AliasConditionEvent extends ActiveRecord\Model
{
    public static $table_name = 'events';
    public static $belongs_to = [
        ['washington_venue', 'class_name' => 'Venue', 'foreign_key' => 'venue_id',
            'conditions' => ['mycity' => 'Washington']],
        ['warner_venue', 'class_name' => 'Venue', 'foreign_key' => 'venue_id',
            'conditions' => ['marquee' => 'Warner Theatre', 'name' => 'Warner Theatre']],
        ['contradicted_venue', 'class_name' => 'Venue', 'foreign_key' => 'venue_id',
            'conditions' => ['marquee' => 'Warner Theatre', 'name' => 'YMCA']],
        ['plain_venue', 'class_name' => 'Venue', 'foreign_key' => 'venue_id',
            'conditions' => ['city' => 'Washington', 'state' => ['DC', null], 'venues.name' => ['Warner Theatre', 'YMCA']]],
    ];
}
