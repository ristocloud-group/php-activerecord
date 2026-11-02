<?php

/**
 * An Author that opts out of the timestamps of a save, as a consumer's model can: it overrides
 * set_timestamps() to do nothing. A save() of it stores neither created_at nor updated_at, whereas a
 * row written straight to its table by Table::insert() gets both (spec §6.2.3).
 */
class OptOutAuthor extends Author
{
    public static $table_name = 'authors';

    public function set_timestamps(): void {}
}
