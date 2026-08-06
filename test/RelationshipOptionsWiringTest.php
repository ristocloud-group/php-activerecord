<?php

// Unit-level test: constructs relationship classes directly, without going through
// Table::set_associations() (which is what normally triggers the lazy
// `require_once 'Relationship.php';` in lib/Table.php). Load explicitly so the classes
// exist regardless of test run order/isolation.
require_once __DIR__ . '/../lib/Relationship.php';

use ActiveRecord\HasMany;
use ActiveRecord\BelongsTo;
use ActiveRecord\RelationshipException;

class RelationshipOptionsWiringTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function test_has_many_without_name_throws_relationship_exception()
    {
        $this->expectException(RelationshipException::class);
        new HasMany(['class_name' => 'Book']); // no index 0
    }

    public function test_belongs_to_without_name_throws_relationship_exception()
    {
        $this->expectException(RelationshipException::class);
        new BelongsTo(['class_name' => 'Author']); // no index 0
    }

    public function test_unknown_option_key_throws_relationship_exception()
    {
        $this->expectException(RelationshipException::class);
        new HasMany(['books', 'bogus_option' => 1]);
    }
}
