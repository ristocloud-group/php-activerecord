<?php

// An alias_attribute named like a real column (city): find() maps it to name, as it
// always has; count/exists/update_all/delete_all and relationship conditions do not.
class ShadowAliasVenue extends ActiveRecord\Model
{
    public static $table_name = 'venues';
    public static $alias_attribute = ['city' => 'name', 'marquee' => 'name'];
}
