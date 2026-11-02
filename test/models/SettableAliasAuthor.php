<?php

/**
 * An author whose $alias_attribute a test sets at run time, in states a declared one cannot be in: an
 * alias named like a column that the load of the table did not see (a column the table gained after it
 * loaded, a SQLite generated column), or one set before the load that a declaration could not vary
 * per connection (a pre-quoted key, the name of a pseudo-column). A model that declares an alias named
 * like a column does not load (AliasedPkAuthor, spec §6.2.6). The test that sets the map puts the empty
 * one back.
 */
class SettableAliasAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $alias_attribute = [];
}
