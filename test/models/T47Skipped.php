<?php

// Postgres serial table whose BEFORE INSERT trigger skips the row: RETURNING returns no row (#47)
class T47Skipped extends ActiveRecord\Model
{
    public static $table_name = 't47_skipped';
}
