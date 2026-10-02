<?php

// authors through an explicit schema ($db). On Postgres the column introspection
// does not resolve a schema-qualified name, so the schema is unknown (no columns):
// hash-condition keys are then left to the database.
class PublicSchemaAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $db = 'public';
    public static $primary_key = 'author_id';
}
