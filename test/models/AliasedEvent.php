<?php

// events, with aliases of two of its columns: the associated model of AliasConditionVenue's
// has_many and has_one, whose declared conditions name them (#144)
class AliasedEvent extends ActiveRecord\Model
{
    public static $table_name = 'events';
    public static $alias_attribute = ['headline' => 'title', 'kind' => 'type'];
}
