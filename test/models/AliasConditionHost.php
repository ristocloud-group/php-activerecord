<?php

// has_many through events, the join shape (events belongs_to its venue): the declared conditions filter
// the target, the model the load finds, so they name its aliases (#144): Venue's mycity is city. A key
// they map is named with the target's table, which the middle table joins: AliasThrough\Venue's
// venue_number is venues.id (events has an id too), and its title is venues.name (events has a title).
// An alias next to its column's own name keeps both conditions, ANDed (#129). plain_venues names a column
// of the middle table (qualified) and one of Venue: its SQL is the one it always had.
class AliasConditionHost extends ActiveRecord\Model
{
    public static $table_name = 'hosts';
    public static $has_many = [
        ['events', 'class_name' => 'Event', 'foreign_key' => 'host_id'],
        ['venues', 'through' => 'events', 'class_name' => 'Venue', 'foreign_key' => 'host_id', 'order' => 'venues.id asc',
            'conditions' => ['mycity' => 'Washington']],
        ['plain_venues', 'through' => 'events', 'class_name' => 'Venue', 'foreign_key' => 'host_id', 'order' => 'venues.id asc',
            'conditions' => ['events.title' => ['Yeah Yeah Yeahs', 'Blah'], 'state' => 'DC']],
        ['shared_column_venues', 'through' => 'events', 'class_name' => 'AliasThrough\Venue', 'foreign_key' => 'host_id',
            'order' => 'venues.id asc', 'conditions' => ['venue_number' => [2, 9], 'state' => 'DC']],
        ['titled_venues', 'through' => 'events', 'class_name' => 'AliasThrough\Venue', 'foreign_key' => 'host_id',
            'order' => 'venues.id asc', 'conditions' => ['title' => 'Warner Theatre']],
        ['warner_venues_twice', 'through' => 'events', 'class_name' => 'AliasThrough\Venue', 'foreign_key' => 'host_id',
            'order' => 'venues.id asc', 'conditions' => ['title' => 'Warner Theatre', 'name' => 'Warner Theatre']],
        ['contradicted_venues', 'through' => 'events', 'class_name' => 'AliasThrough\Venue', 'foreign_key' => 'host_id',
            'order' => 'venues.id asc', 'conditions' => ['title' => 'Warner Theatre', 'name' => 'YMCA']],
    ];
}
