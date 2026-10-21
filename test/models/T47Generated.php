<?php

// Postgres pk that is a stored generated column: read back with RETURNING (#47)
class T47Generated extends ActiveRecord\Model
{
    public static $table_name = 't47_generated';
}
