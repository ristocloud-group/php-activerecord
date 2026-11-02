<?php

/**
 * An alias named like the attribute of a column whose name differs: the column "space name" is the
 * attribute space_name (#92), a name a hash condition uses for it, so the alias would hide that column
 * (#144).
 */
class OddColumnShadowAlias extends ActiveRecord\Model
{
    public static $table_name = 'odd_columns';
    public static $alias_attribute = ['space_name' => 'dash_name'];
}
