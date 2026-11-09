<?php

/**
 * An Author whose key is named by an alias, ref for author_id: keyset paging by ref reads the last key of a page from
 * the attribute author_id, the one Model::attributes() keys it by (spec §4.3, Review Focus 1).
 */
class KeysetAliasAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $pk = 'author_id';
    public static $alias_attribute = ['ref' => 'author_id'];
}
