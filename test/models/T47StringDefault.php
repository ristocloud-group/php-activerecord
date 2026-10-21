<?php

// Postgres varchar pk with a quoted-literal default: not filled by the database, the convention sequence is used as on master (#47)
class T47StringDefault extends ActiveRecord\Model
{
    public static $table_name = 't47_string_defaults';
}
