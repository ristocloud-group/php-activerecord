<?php

/**
 * An Author that counts the models built of it, in its after_construct callback: a read that builds no
 * model leaves the count as it was.
 */
class ConstructionCountingAuthor extends Author
{
    public static $table_name = 'authors';

    /** How many models were built since a test last reset the counter */
    public static int $constructed = 0;

    public function after_construct(): void
    {
        self::$constructed++;
    }
}
