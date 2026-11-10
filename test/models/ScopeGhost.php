<?php

/**
 * A model on a table that does not exist: reading its scopes must not need the table.
 */
class ScopeGhost extends ActiveRecord\Model
{
    public static $table_name = 'no_such_table_for_scopes';
}
