<?php

// has_many through events, the join shape (events belongs_to its venue): the declared conditions filter
// the target, Venue, the model the load finds, so they name its aliases (#144): mycity is city.
// plain_venues names a column of the middle table (qualified) and one of Venue: its SQL is the one it
// always had.
class AliasConditionHost extends ActiveRecord\Model
{
    public static $table_name = 'hosts';
    public static $has_many = [
        ['events', 'class_name' => 'Event', 'foreign_key' => 'host_id'],
        ['venues', 'through' => 'events', 'class_name' => 'Venue', 'foreign_key' => 'host_id', 'order' => 'venues.id asc',
            'conditions' => ['mycity' => 'Washington']],
        ['plain_venues', 'through' => 'events', 'class_name' => 'Venue', 'foreign_key' => 'host_id', 'order' => 'venues.id asc',
            'conditions' => ['events.title' => ['Yeah Yeah Yeahs', 'Blah'], 'state' => 'DC']],
    ];
}
