<?php

/**
 * An author whose alias is named like its primary key column (a consumer's model can have one):
 * the pk of a finder is the column's own name, never read through that alias.
 */
class AliasedPkAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $alias_attribute = ['author_id' => 'name'];
}
