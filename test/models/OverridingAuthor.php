<?php

/**
 * An Author whose all() adds a condition when the caller gives none, as a consumer's base class
 * does (the monolith base model injects its soft-delete condition): the legacy finders run such an override.
 */
class OverridingAuthor extends Author
{
    public static $table_name = 'authors';

    public static function all(/* ... */)
    {
        $args = func_get_args();

        if ([] === $args) {
            $args[] = ['conditions' => ['author_id' => 1]];
        }

        return parent::all(...$args);
    }
}
