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

    public function test_id_shortcut_on_table_without_primary_key_stays_unset()
    {
        $this->assert_false(isset(PklessItem::first()->id));
        $this->assert_same('fallback', PklessItem::first()->id ?? 'fallback');
        $this->assert_false(isset((new PklessItem())->id));
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
