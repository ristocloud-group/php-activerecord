<?php

/**
 * IDENTITY case: wristbands.id is GENERATED ALWAYS AS IDENTITY, so the database
 * alone numbers it; create() leaves it out and reads the stored id back.
 *
 * @property int         $id
 * @property string|null $holder
 */
class Wristband extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
}
