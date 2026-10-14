<?php

/**
 * The Postgres twin of Widget, for the Postgres part of the example.
 *
 * @property int    $id
 * @property string $name
 * @property string $category
 * @property string $price
 * @property int    $in_stock
 */
class PgWidget extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
    public static $table_name = 'pg_widgets';
}
