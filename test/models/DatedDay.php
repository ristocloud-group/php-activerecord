<?php

/**
 * dated_counts read by the DATE column of its composite pk alone: a model whose pk is one DATE column, which
 * a find by pk binds as the date the column stores.
 */
class DatedDay extends ActiveRecord\Model
{
    public static $table_name = 'dated_counts';
    public static $pk = 'day';
}
