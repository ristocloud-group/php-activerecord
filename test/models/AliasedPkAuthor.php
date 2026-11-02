<?php

/**
 * An author whose alias is named like its primary key column (a consumer's model could have one): a
 * configuration error, so its table does not load (#144, spec §6.2.6).
 */
class AliasedPkAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $alias_attribute = ['author_id' => 'name'];
}
