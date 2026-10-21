<?php

// Postgres integer pk with a constant default (DEFAULT 0): not filled by the database, the convention sequence is used as on master (#47)
class T47ConstantDefault extends ActiveRecord\Model
{
    public static $table_name = 't47_constant_defaults';
}
