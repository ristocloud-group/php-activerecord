<?php

/**
 * A child of SettableAliasAuthor on another table, venues (it has a city column and no author_id): it
 * shares the alias map a test sets on its parent, so the check at load reads an inherited map against the
 * table of the class that loads.
 */
class SettableAliasVenue extends SettableAliasAuthor
{
    public static $table_name = 'venues';
}
