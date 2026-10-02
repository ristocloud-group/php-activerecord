<?php

// '"public".authors' as a pre-quoted $table_name, without `$db`: one relation name
// (introspects nothing), not the schema-qualified table of PublicSchemaAuthor.
class PublicQuotedTableNameAuthor extends ActiveRecord\Model
{
    public static $table_name = '"public".authors';
}
