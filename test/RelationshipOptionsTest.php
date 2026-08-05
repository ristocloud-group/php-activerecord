<?php

use ActiveRecord\RelationshipOptions;
use ActiveRecord\RelationshipException;

class RelationshipOptionsTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function test_name_is_read_from_index_zero()
    {
        $opts = RelationshipOptions::from_array(['books']);
        $this->assert_equals('books', $opts->name);
    }

    public function test_class_key_takes_precedence_over_class_name()
    {
        $opts = RelationshipOptions::from_array(['books', 'class' => 'Book', 'class_name' => 'Ignored']);
        $this->assert_equals('Book', $opts->class_name);
    }

    public function test_class_name_used_when_class_absent()
    {
        $opts = RelationshipOptions::from_array(['books', 'class_name' => 'Book']);
        $this->assert_equals('Book', $opts->class_name);
    }

    public function test_scalar_foreign_key_is_wrapped_to_list()
    {
        $opts = RelationshipOptions::from_array(['books', 'foreign_key' => 'author_id']);
        $this->assert_equals(['author_id'], $opts->foreign_key);
    }

    public function test_array_foreign_key_is_reindexed()
    {
        $opts = RelationshipOptions::from_array(['books', 'foreign_key' => [3 => 'a', 7 => 'b']]);
        $this->assert_equals(['a', 'b'], $opts->foreign_key);
    }

    public function test_absent_keys_default_to_empty_or_null()
    {
        $opts = RelationshipOptions::from_array(['books']);
        $this->assert_equals([], $opts->foreign_key);
        $this->assert_equals([], $opts->primary_key);
        $this->assert_null($opts->class_name);
        $this->assert_null($opts->through);
    }

    public function test_has_many_options_are_captured()
    {
        $opts = RelationshipOptions::from_array(['payments', 'through' => 'orders', 'source' => 'payment', 'primary_key' => 'user_id']);
        $this->assert_equals('orders', $opts->through);
        $this->assert_equals('payment', $opts->source);
        $this->assert_equals(['user_id'], $opts->primary_key);
    }

    public function test_missing_name_throws_relationship_exception()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['class_name' => 'Book']); // no index 0
    }

    public function test_empty_string_name_throws()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['']);
    }

    public function test_empty_string_foreign_key_throws()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['books', 'foreign_key' => '']);
    }

    public function test_non_string_foreign_key_element_throws()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['books', 'foreign_key' => ['author_id', null]]);
    }

    public function test_non_string_primary_key_throws()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['books', 'primary_key' => 123]);
    }

    public function test_non_string_class_throws()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['books', 'class' => 123]);
    }

    public function test_empty_class_name_throws()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['books', 'class_name' => '']);
    }

    public function test_non_string_through_throws()
    {
        $this->expectException(RelationshipException::class);
        RelationshipOptions::from_array(['books', 'through' => 5]);
    }
}
