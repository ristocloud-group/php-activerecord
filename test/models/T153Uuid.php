<?php

// Postgres uuid pk with a non-sequence default, gen_random_uuid() (#153)
class T153Uuid extends ActiveRecord\Model
{
    public static $table_name = 't153_uuids';
}
