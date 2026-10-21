<?php

// Postgres integer pk without a default: the {table}_{pk}_seq convention is the last resort (#47)
class T47NoDefault extends ActiveRecord\Model
{
    public static $table_name = 't47_no_defaults';
}
