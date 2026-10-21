<?php

/**
 * Non-sequence default case: uploads.id is a uuid DEFAULT gen_random_uuid(),
 * so there is no sequence at all; create() leaves it out and reads the stored
 * uuid back.
 *
 * @property string      $id
 * @property string|null $filename
 */
class Upload extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
}
