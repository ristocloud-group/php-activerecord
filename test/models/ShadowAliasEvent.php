<?php

// A relationship hash condition on the target's shadowing alias (city): the real column.
class ShadowAliasEvent extends ActiveRecord\Model
{
    public static $table_name = 'events';
    public static $belongs_to = [['venue', 'class_name' => 'ShadowAliasVenue', 'foreign_key' => 'venue_id', 'conditions' => ['city' => 'Washington']]];
}
