<?php

namespace AliasThrough;

// venues, the target of AliasConditionHost's has_many through events in the join shape, which keys the
// middle table by the target's class name (events.venue_id): hence a Venue of its own namespace, whose
// aliases name a column the middle table has too (venue_number is id) and are named like a column of the
// middle table (title is name; events has a title) (#144)
class Venue extends \ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $alias_attribute = ['venue_number' => 'id', 'title' => 'name'];
}
