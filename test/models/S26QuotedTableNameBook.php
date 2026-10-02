<?php

// a pre-quoted, schema-like $table_name without `$db`: introspected as before
// (no schema split), i.e. as one relation name.
class S26QuotedTableNameBook extends ActiveRecord\Model
{
    public static $table_name = '"s26".books';
}
