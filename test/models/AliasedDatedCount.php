<?php

// dated_counts, whose DATE column day an alias names too: happened_on
class AliasedDatedCount extends ActiveRecord\Model
{
    public static $table_name = 'dated_counts';
    public static $alias_attribute = ['happened_on' => 'day'];
}
