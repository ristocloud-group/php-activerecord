<?php

use ActiveRecord\Config;
use ActiveRecord\MassAssignmentException;
use ActiveRecord\UndefinedPropertyException;

// attr_protected lists the author_id foreign key
class BookAttrProtectedForeignKey extends ActiveRecord\Model
{
    public static $pk = 'book_id';
    public static $table_name = 'books';
    public static $attr_protected = ['author_id'];
}

class AuthorWithProtectedForeignKeyBooks extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['books', 'class_name' => 'BookAttrProtectedForeignKey', 'foreign_key' => 'author_id']];
    public static $has_one = [['book', 'class_name' => 'BookAttrProtectedForeignKey', 'foreign_key' => 'author_id']];
}

// attr_accessible allows the foreign key; after_construct records the value it sees
class BookTrackingForeignKey extends ActiveRecord\Model
{
    public static $pk = 'book_id';
    public static $table_name = 'books';
    public static $attr_accessible = ['name', 'author_id'];
    public static $after_construct = ['track'];
    /** @var list<mixed> */
    public static array $seen = [];

    public function track(): void
    {
        self::$seen[] = $this->author_id;
    }
}

class AuthorWithTrackedBooks extends ActiveRecord\Model
{
    public static $pk = 'author_id';
    public static $table_name = 'authors';
    public static $has_many = [['books', 'class_name' => 'BookTrackingForeignKey', 'foreign_key' => 'author_id']];
}

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

    public function test_create_throws_before_inserting()
    {
        $count = BookAttrProtected::count();

        $e = $this->expect_blocked(fn() => BookAttrProtected::create(['author_id' => 1, 'name' => 'sneaky']));

        $this->assert_same("BookAttrProtected: mass assignment of attribute 'name' blocked by attr_protected", $e->getMessage());
        $this->assert_equals($count, BookAttrProtected::count());
    }

    /*
     * Association builders (build_* / create_*) assign the foreign key they inject
     * directly, like Rails: the associated model's $attr_accessible /
     * $attr_protected and strict mass assignment do not apply to it (it used to be
     * dropped, leaving an orphan record, or to throw). The attributes passed to the
     * builder stay guarded.
     */
    public function test_association_builder_assigns_injected_foreign_key_when_strict_is_off()
    {
        Config::instance()->set_strict_mass_assignment(false);
        $author = AuthorWithGuardedBooks::find(1);

        $built = $author->build_books(['name' => 'built']);
        $this->assert_same('built', $built->name);
        $this->assert_equals(1, $built->author_id);

        $created = $author->create_books(['name' => 'created']);
        $this->assert_false($created->is_new_record());
        $this->assert_equals(1, BookAttrAccessibleNameOnly::find($created->book_id)->author_id);
    }

    public function test_association_builder_assigns_injected_foreign_key_when_strict_is_on()
    {
        $author = AuthorWithGuardedBooks::find(1);
        $count = BookAttrAccessibleNameOnly::count();

        $this->assert_equals(1, $author->build_books(['name' => 'built'])->author_id);

        $created = $author->create_books(['name' => 'created']);
        $this->assert_equals(1, BookAttrAccessibleNameOnly::find($created->book_id)->author_id);
        $this->assert_equals($count + 1, BookAttrAccessibleNameOnly::count());
    }

    public function test_association_builder_assigns_foreign_key_listed_in_attr_protected()
    {
        $author = AuthorWithProtectedForeignKeyBooks::find(2);

        $this->assert_equals(2, $author->build_books(['name' => 'built'])->author_id);
        $this->assert_equals(2, $author->build_book(['name' => 'built one'])->author_id);

        $created = $author->create_book(['name' => 'created']);
        $this->assert_equals(2, BookAttrProtectedForeignKey::find($created->book_id)->author_id);
        $this->assert_same('created', $created->name);
    }

    public function test_association_builder_still_guards_the_attributes_passed_to_it()
    {
        $author = AuthorWithGuardedBooks::find(1);
        $count = BookAttrAccessibleNameOnly::count();

        // only the attribute passed in is reported, not the injected foreign key
        $e = $this->expect_blocked(fn() => $author->create_books(['name' => 'created', 'secondary_author_id' => 2]));
        $this->assert_same("BookAttrAccessibleNameOnly: mass assignment of attribute 'secondary_author_id' blocked by attr_accessible", $e->getMessage());
        $this->assert_equals($count, BookAttrAccessibleNameOnly::count());

        // a foreign key passed in is an attribute like any other: guarded, and not replaced
        $e = $this->expect_blocked(fn() => $author->build_books(['author_id' => 4]));
        $this->assert_same("BookAttrAccessibleNameOnly: mass assignment of attribute 'author_id' blocked by attr_accessible", $e->getMessage());

        Config::instance()->set_strict_mass_assignment(false);
        $this->assert_null($author->build_books(['author_id' => 4])->author_id);
    }

    public function test_association_builder_passes_an_allowed_foreign_key_through_mass_assignment()
    {
        // an allowed foreign key is still mass-assigned with the other attributes, so
        // after_construct already sees it
        BookTrackingForeignKey::$seen = [];
        $author = AuthorWithTrackedBooks::find(1);

        $this->assert_equals(1, $author->build_books(['name' => 'built'])->author_id);
        $this->assert_not_empty(BookTrackingForeignKey::$seen);
        $this->assert_same([1], array_values(array_unique(BookTrackingForeignKey::$seen)));
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
