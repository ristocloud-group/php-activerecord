<?php

use ActiveRecord\Config;
use ActiveRecord\MassAssignmentException;
use ActiveRecord\UndefinedPropertyException;

class StrictMassAssignmentTest extends DatabaseTest
{
    private bool $original_strict;

    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);
        $this->original_strict = Config::instance()->get_strict_mass_assignment();
        Config::instance()->set_strict_mass_assignment(true);
    }

    public function tear_down()
    {
        Config::instance()->set_strict_mass_assignment($this->original_strict);
        parent::tear_down();
    }

    /**
     * Runs $assign and returns the MassAssignmentException it must throw.
     */
    private function expect_blocked(callable $assign): MassAssignmentException
    {
        try {
            $assign();
        } catch (MassAssignmentException $e) {
            return $e;
        }
        $this->fail('expected ActiveRecord\MassAssignmentException');
    }

    public function test_default_off_drops_blocked_keys()
    {
        Config::instance()->set_strict_mass_assignment(false);

        $book = new BookAttrProtected(['name' => 'sneaky', 'author_id' => 1]);
        $this->assert_null($book->name);
        $this->assert_equals(1, $book->author_id);
    }

    public function test_exception_is_a_model_exception()
    {
        $e = $this->expect_blocked(fn() => new BookAttrProtected(['name' => 'sneaky']));
        $this->assert_instance_of(ActiveRecord\ModelException::class, $e);
    }

    public function test_attr_accessible_throws()
    {
        $e = $this->expect_blocked(fn() => new BookAttrAccessible(['name' => 'sneaky']));
        $this->assert_same("BookAttrAccessible: mass assignment of attribute 'name' blocked by attr_accessible", $e->getMessage());
    }

    public function test_attr_protected_throws()
    {
        $e = $this->expect_blocked(fn() => new BookAttrProtected(['name' => 'sneaky']));
        $this->assert_same("BookAttrProtected: mass assignment of attribute 'name' blocked by attr_protected", $e->getMessage());
    }

    public function test_alias_throws_with_both_names()
    {
        $e = $this->expect_blocked(fn() => new BookAttrProtected(['name_alias' => 'sneaky']));
        $this->assert_same("BookAttrProtected: mass assignment of attribute 'name' blocked by attr_protected (passed as 'name_alias')", $e->getMessage());
    }

    public function test_id_shortcut_throws_for_attr_protected_pk()
    {
        $e = $this->expect_blocked(fn() => new BookAttrProtected(['id' => 999]));
        $this->assert_same("BookAttrProtected: mass assignment of attribute 'book_id' blocked by attr_protected (passed as 'id')", $e->getMessage());
    }

    public function test_id_shortcut_throws_for_non_whitelisted_pk()
    {
        $e = $this->expect_blocked(fn() => new BookAttrAccessible(['id' => 999]));
        $this->assert_same("BookAttrAccessible: mass assignment of attribute 'book_id' blocked by attr_accessible (passed as 'id')", $e->getMessage());
    }

    public function test_id_shortcut_throws_on_table_without_primary_key()
    {
        $e = $this->expect_blocked(fn() => new PklessItemAttrAccessible(['code' => 7, 'id' => 5]));
        $this->assert_same("PklessItemAttrAccessible: mass assignment of attribute '' blocked by attr_accessible (passed as 'id')", $e->getMessage());
    }

    public function test_mix_of_allowed_and_blocked_keys_lists_only_blocked_ones()
    {
        $e = $this->expect_blocked(fn() => new BookAttrProtected([
            'author_id' => 1,
            'name' => 'sneaky',
            'secondary_author_alias' => 2,
            'protected_pk_alias' => 999,
        ]));
        $this->assert_same(
            "BookAttrProtected: mass assignment of attribute 'name' blocked by attr_protected; "
            . "BookAttrProtected: mass assignment of attribute 'book_id' blocked by attr_protected (passed as 'protected_pk_alias')",
            $e->getMessage()
        );
    }

    public function test_nothing_is_assigned_when_it_throws()
    {
        $book = BookAttrProtected::find(1);
        $before = $book->attributes();

        // the allowed key comes first, so a one-pass guard would already have assigned it
        $this->expect_blocked(fn() => $book->set_attributes(['author_id' => 2, 'secondary_author_id' => 1, 'name' => 'sneaky']));

        $this->assert_equals($before, $book->attributes());
        $this->assert_false($book->is_dirty());
    }

    public function test_update_attributes_throws_before_saving()
    {
        $book = BookAttrProtected::find(1);

        $this->expect_blocked(fn() => $book->update_attributes(['author_id' => 2, 'name' => 'sneaky']));

        $this->assert_false($book->is_dirty());
        $this->assert_equals(1, BookAttrProtected::find(1)->author_id);
    }

    public function test_blocked_key_wins_over_unknown_attribute()
    {
        $e = $this->expect_blocked(fn() => new BookAttrProtected(['no_such_attribute' => 1, 'name' => 'sneaky']));
        $this->assert_same("BookAttrProtected: mass assignment of attribute 'name' blocked by attr_protected", $e->getMessage());
    }

    public function test_unknown_attribute_without_blocked_keys_still_throws_undefined_property()
    {
        $this->expectException(UndefinedPropertyException::class);
        new BookAttrProtected(['author_id' => 1, 'no_such_attribute' => 1]);
    }

    public function test_no_blocked_keys_assigns_normally()
    {
        $book = new BookAttrProtected(['author_id' => 1, 'secondary_author_alias' => 2]);
        $this->assert_equals(1, $book->author_id);
        $this->assert_equals(2, $book->secondary_author_id);

        $item = new PklessItemAttrAccessible(['code' => 7]);
        $this->assert_same(['code' => 7, 'name' => null], $item->attributes());
    }

    public function test_models_without_guards_are_unaffected()
    {
        $book = new Book(['name' => 'free', 'author_id' => 1]);
        $this->assert_equals('free', $book->name);
    }

    public function test_single_attribute_assignment_is_not_affected()
    {
        $book = new BookAttrProtected();
        $book->name = 'direct';
        $book->name_alias = 'direct alias';
        $book->id = 5;
        $this->assert_equals('direct alias', $book->name);
        $this->assert_equals(5, $book->book_id);
    }

    public function test_unguarded_instantiation_from_find_is_not_affected()
    {
        $book = BookAttrProtected::find(1);
        $this->assert_equals(1, $book->book_id);
        $this->assert_equals('Ancient Art of Main Tanking', $book->name);
    }
}
