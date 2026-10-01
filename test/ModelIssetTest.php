<?php

use ActiveRecord\UndefinedPropertyException;

/*
 * isset() / ?? / empty() must agree with reads (#54): __isset() resolves a name
 * the way __get()/read_attribute() do, so the 'id' primary-key shortcut and a
 * declared $delegate are "set" like an attribute (true even for a null value).
 *
 * Fixture data:
 *   authors (pk author_id): 1 "Tito" (parent 3), 3 "Bill Clinton" (parent 1)
 *   events: 1 -> venue 1 (state NY), host 1 "David Letterman"
 *           6 -> venue 500 (no such venue), host 4 "Funny Guy"
 *   composite_items: 5 (author_ref 3, parent_ref 1) "mirror"
 *   books (no timestamp columns, nullable author_id): 1 -> author 1
 *   venues have no updated_at/created_at; authors have both (null in the fixtures)
 */

// authors keyed by (author_id, parent_author_id): 'id' maps to the first column
class IssetCompositePkAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $primary_key = ['author_id', 'parent_author_id'];
}

// a real `id` column that is not the primary key keeps priority over the shortcut
class IssetIdColumnItem extends ActiveRecord\Model
{
    public static $table_name = 'composite_items';
    public static $primary_key = 'author_ref';
}

// alias, getter and relationship named `id` keep priority over the shortcut (pk author_id)
class IssetAliasIdAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $alias_attribute = ['id' => 'name'];
}

class IssetGetterIdAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';

    public function get_id()
    {
        return 'from getter';
    }
}

class IssetRelationshipIdAuthor extends ActiveRecord\Model
{
    public static $table_name = 'authors';
    public static $belongs_to = [['id', 'class_name' => 'Author', 'foreign_key' => 'parent_author_id']];
}

// delegates named like the timestamps their own table lacks: set_timestamps() must
// not write through them (venues have no timestamp columns, authors have both)
class IssetTimestampDelegateEvent extends ActiveRecord\Model
{
    public static $table_name = 'events';
    public static $belongs_to = [['venue']];
    public static $delegate = [['updated_at', 'created_at', 'to' => 'venue']];
}

class IssetTimestampDelegateBook extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $belongs_to = [['author']];
    public static $delegate = [['updated_at', 'created_at', 'to' => 'author']];
}

class ModelIssetTest extends DatabaseTest
{
    public function test_id_shortcut_is_set_when_pk_is_not_id()
    {
        $author = Author::find(1);

        $this->assert_true(isset($author->id));
        $this->assert_same(1, $author->id);
        $this->assert_same(1, $author->id ?? 'fallback');
        $this->assert_false(empty($author->id));
    }

    public function test_id_shortcut_reads_null_pk_of_new_record()
    {
        $author = new Author();

        // exactly like the pk attribute itself: present, null
        $this->assert_true(isset($author->author_id));
        $this->assert_true(isset($author->id));
        $this->assert_null($author->id);
        $this->assert_same('fallback', $author->id ?? 'fallback');
        $this->assert_true(empty($author->id));
    }

    public function test_id_shortcut_follows_pk_assignment()
    {
        $author = new Author();
        $author->id = 42;

        $this->assert_same(42, $author->author_id);
        $this->assert_true(isset($author->id));
        $this->assert_same(42, $author->id ?? 'fallback');
    }

    public function test_id_shortcut_is_unset_when_pk_was_not_selected()
    {
        $author = Author::first(['select' => 'name']);

        // exactly like the pk attribute itself: not loaded, so not set and not readable
        $this->assert_false(isset($author->author_id));
        $this->assert_false(isset($author->id));
        $this->assert_same('fallback', $author->id ?? 'fallback');
        $this->assert_true(empty($author->id));
        $this->expectException(UndefinedPropertyException::class);
        $author->id;
    }

    public function test_id_shortcut_maps_to_first_column_of_composite_pk()
    {
        $author = IssetCompositePkAuthor::first(['conditions' => ['author_id' => 3]]);

        $this->assert_true(isset($author->id));
        $this->assert_same(3, $author->id ?? 'fallback');

        $new = new IssetCompositePkAuthor();
        $this->assert_true(isset($new->id));
        $this->assert_null($new->id);
    }

    public function test_real_id_attribute_keeps_priority_over_id_shortcut()
    {
        $item = IssetIdColumnItem::first(['conditions' => ['id' => 5]]);

        $this->assert_same(3, $item->author_ref);
        $this->assert_true(isset($item->id));
        $this->assert_same(5, $item->id ?? 'fallback');
    }

    public function test_alias_getter_and_relationship_named_id_keep_priority_over_id_shortcut()
    {
        $alias = IssetAliasIdAuthor::find(1);
        $this->assert_true(isset($alias->id));
        $this->assert_same('Tito', $alias->id ?? 'fallback');

        $getter = new IssetGetterIdAuthor();
        $this->assert_true(isset($getter->id));
        $this->assert_same('from getter', $getter->id ?? 'fallback');

        $relationship = IssetRelationshipIdAuthor::find(1);
        $this->assert_true(isset($relationship->id));
        $this->assert_same(3, $relationship->id->author_id);
        $this->assert_true(isset((new IssetRelationshipIdAuthor())->id));
        $this->assert_same('fallback', (new IssetRelationshipIdAuthor())->id ?? 'fallback');
    }

    public function test_id_shortcut_on_table_without_primary_key_stays_unset()
    {
        $this->assert_false(isset(PklessItem::first()->id));
        $this->assert_same('fallback', PklessItem::first()->id ?? 'fallback');
        $this->assert_false(isset((new PklessItem())->id));
    }

    public function test_id_shortcut_assigned_on_table_without_primary_key_reads_back_but_stays_unset()
    {
        // pinned legacy behavior, unchanged by #54: the write lands on the '' key
        $item = new PklessItem();
        $item->id = 5;

        $this->assert_same(5, $item->id);
        $this->assert_false(isset($item->id));
    }

    public function test_has_many_build_on_unsaved_parent_with_non_id_pk_gets_null_foreign_key()
    {
        // before #54 reading the parent's null pk through `id` threw UndefinedPropertyException
        $book = (new Author())->build_books(['name' => 'built']);

        $this->assert_true($book->is_new_record());
        $this->assert_null($book->author_id);
    }

    public function test_has_many_create_on_unsaved_parent_with_non_id_pk_inserts_null_foreign_key()
    {
        $count = Book::count();

        $book = (new Author())->create_books(['name' => 'created']);

        $this->assert_false($book->is_new_record());
        $this->assert_null($book->author_id);
        $this->assert_equals($count + 1, Book::count());
        $this->assert_null(Book::find($book->book_id)->author_id);
    }

    public function test_set_timestamps_skips_delegated_timestamps_when_target_is_null()
    {
        $event = IssetTimestampDelegateEvent::find(6);
        $this->assert_null($event->venue);
        $event->title = 'renamed';
        $this->assert_true($event->save());
        $this->assert_same('renamed', IssetTimestampDelegateEvent::find(6)->title);

        $new = new IssetTimestampDelegateEvent(['venue_id' => 500, 'host_id' => 4, 'title' => 'new']);
        $this->assert_true($new->save());
        $this->assert_false($new->is_new_record());
    }

    public function test_set_timestamps_skips_delegated_timestamps_the_target_lacks()
    {
        $event = IssetTimestampDelegateEvent::find(1);
        $this->assert_not_null($event->venue);
        $event->title = 'renamed';

        $this->assert_true($event->save());
        $this->assert_false($event->venue->is_dirty());
    }

    public function test_set_timestamps_leaves_delegate_target_timestamps_untouched()
    {
        $book = IssetTimestampDelegateBook::find(1);
        $author = $book->author;
        $book->name = 'renamed';
        $this->assert_true($book->save());
        $this->assert_null($author->updated_at);
        $this->assert_false($author->is_dirty());

        $new = new IssetTimestampDelegateBook(['name' => 'new', 'author_id' => 1]);
        $author = $new->author;
        $this->assert_true($new->save());
        $this->assert_null($author->created_at);
        $this->assert_null($author->updated_at);
        $this->assert_false($author->is_dirty());
    }

    public function test_delegate_is_set_when_target_present()
    {
        $event = Event::find(1);

        $this->assert_true(isset($event->state));
        $this->assert_same('NY', $event->state ?? 'fallback');
        $this->assert_false(empty($event->state));
    }

    public function test_prefixed_delegate_is_set_when_target_present()
    {
        $event = Event::find(1);

        $this->assert_true(isset($event->woot_name));
        $this->assert_same('David Letterman', $event->woot_name ?? 'fallback');
        $this->assert_false(empty($event->woot_name));
    }

    public function test_delegate_is_set_when_delegated_value_is_null()
    {
        $event = Event::find(1);
        $event->venue->assign_attribute('state', null);

        $this->assert_true(isset($event->state));
        $this->assert_same('fallback', $event->state ?? 'fallback');
        $this->assert_true(empty($event->state));
    }

    public function test_delegate_is_set_when_target_is_null()
    {
        $event = Event::find(6);

        $this->assert_null($event->venue);
        $this->assert_true(isset($event->state));
        $this->assert_same('fallback', $event->state ?? 'fallback');
        $this->assert_true(empty($event->state));
    }

    public function test_prefixed_delegate_is_set_when_target_is_null()
    {
        $event = new Event();

        $this->assert_true(isset($event->woot_name));
        $this->assert_same('fallback', $event->woot_name ?? 'fallback');
        $this->assert_true(empty($event->woot_name));
    }

    public function test_names_that_are_not_delegated_stay_unset()
    {
        $event = Event::find(1);

        // 'name' is only delegated with the 'woot' prefix
        $this->assert_false(isset($event->name));
        $this->assert_false(isset($event->attribute_does_not_exist));
        $this->assert_same('fallback', $event->attribute_does_not_exist ?? 'fallback');
    }
}
