<?php

// Relationship hash conditions keyed by the target's alias_attribute names
// (Venue: marquee => name): mapped like a finder's, alias and column both kept.
class MarqueeEvent extends ActiveRecord\Model
{
    public static $table_name = 'events';
    public static $belongs_to = [
        ['venue', 'conditions' => ['marquee' => 'Warner Theatre']],
        ['scoped_venue', 'class_name' => 'Venue', 'foreign_key' => 'venue_id', 'conditions' => ['name' => 'Warner Theatre', 'marquee' => 'Blender Theater at Gramercy']],
    ];
}
