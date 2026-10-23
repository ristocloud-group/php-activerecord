<?php

// belongs_to OddColumnAliased (odd_columns), whose declared hash conditions name its columns as a finder
// names them (#144, #92): the alias nick is the attribute space_name, the column "space name"; on
// Postgres, whose quoted names are case-sensitive, the attribute camelcase is the column "camelCase".
// An alias next to its column's attribute name keeps both conditions, ANDed (#129).
class AliasConditionOddEvent extends ActiveRecord\Model
{
    public static $table_name = 'events';
    public static $belongs_to = [
        ['nick_kept', 'class_name' => 'OddColumnAliased', 'foreign_key' => 'venue_id',
            'conditions' => ['nick' => 'keep']],
        ['space_kept', 'class_name' => 'OddColumnAliased', 'foreign_key' => 'venue_id',
            'conditions' => ['space_name' => 'keep', 'camelcase' => 'x']],
        ['nick_contradicted', 'class_name' => 'OddColumnAliased', 'foreign_key' => 'venue_id',
            'conditions' => ['nick' => 'keep', 'space_name' => 'drop']],
    ];
}
