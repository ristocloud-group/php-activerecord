<?php

// composite_items, with an alias of its title: the associated model of AliasConditionCompositeAuthor (#144)
class AliasedCompositeItem extends ActiveRecord\Model
{
    public static $table_name = 'composite_items';
    public static $alias_attribute = ['label' => 'title'];
}
