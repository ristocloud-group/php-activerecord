<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\Model;
use ActiveRecord\ReadOnlyException;
use ActiveRecord\RecordNotFound;

/*
 * GH #41: update()/delete() build their WHERE from the record's current pk
 * values. A null value turned it into `WHERE pk IS NULL` (no row, reported as
 * success) and a pk changed after loading made the statement target the row
 * under the NEW value (another row overwritten or deleted). Both are refused
 * with an ActiveRecordException before any SQL and any before_* callback.
 */

// composite primary key (user_id, story_id), introspected from the table
class PkGuardReadReceipt extends ActiveRecord\Model
{
    public static $table_name = 'news_read_receipts';
}

// a value object such as a UUID: assign_attribute() does not cast objects, PDO binds its string
final class PkGuardCodeValue implements Stringable
{
    public function __construct(private string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

// string composite primary key (owner, code); the database fills `code` when it is not sent
class PkGuardCodedItem extends ActiveRecord\Model
{
    public static $table_name = 'coded_items';
}

// assigns a value-object `code` in before_create, as a key generator would
class PkGuardGeneratedCodeItem extends ActiveRecord\Model
{
    public static $table_name = 'coded_items';
    public static $before_create = ['generate_code'];

    public function generate_code(): void
    {
        $this->code = new PkGuardCodeValue('generated');
    }
}

// composite primary key (day DATE, at DATETIME)
class PkGuardDatedCount extends ActiveRecord\Model
{
    public static $table_name = 'dated_counts';
}

// the same table keyed by its DATE column alone (its fixture days are unique)
class PkGuardDatedDay extends ActiveRecord\Model
{
    public static $table_name = 'dated_counts';
    public static $primary_key = 'day';
}

class PrimaryKeyWriteGuardTest extends DatabaseTest
{
    /** @var list<string> */
    private array $fired = [];

    /**
     * Records every validation/write callback that fires on $class.
     *
     * @param class-string<Model> $class
     */
    private function track_callbacks(string $class): void
    {
        $names = ['before_validation', 'before_validation_on_update', 'after_validation', 'before_save',
            'before_update', 'after_update', 'after_save', 'before_destroy', 'after_destroy'];

        foreach ($names as $name) {
            $class::table()->callback->register($name, function () use ($name) {
                $this->fired[] = $name;
            });
        }
    }

    /**
     * Runs $write, which must throw exactly an ActiveRecordException with
     * $message, without issuing any SQL or firing any callback.
     *
     * @param class-string<Model> $class
     */
    private function assert_refused(string $message, callable $write, string $class = Author::class): void
    {
        $this->fired = [];
        $this->track_callbacks($class);
        $conn = $class::connection();
        $conn->last_query = 'no query';

        try {
            $write();
            $this->fail("expected ActiveRecordException: $message");
        } catch (ActiveRecordException $e) {
            $this->assert_same(ActiveRecordException::class, get_class($e));
            $this->assert_same($message, $e->getMessage());
        }

        $this->assert_same('no query', $conn->last_query);
        $this->assert_same([], $this->fired);
    }

    private function coded_item_name(string $owner, string $code): ?string
    {
        return PkGuardCodedItem::first(['conditions' => ['owner' => $owner, 'code' => $code]])?->name;
    }

    /**
     * @return list<string> author_id=name of every author, in pk order
     */
    private function author_rows(): array
    {
        return array_map(fn(Author $a) => $a->author_id . '=' . $a->name, Author::all(['order' => 'author_id']));
    }

    public function test_update_attribute_on_a_new_record_is_refused()
    {
        $author = new Author();

        $this->assert_refused(
            'Cannot update, primary key value is null for: Author (author_id)',
            fn() => $author->update_attribute('name', 'ghost')
        );
    }

    public function test_delete_on_a_new_record_is_refused()
    {
        $author = new Author();
        $author->name = 'never saved';

        $this->assert_refused(
            'Cannot delete, primary key value is null for: Author (author_id)',
            fn() => $author->delete()
        );
    }

    public function test_save_with_a_nulled_pk_is_refused_before_validation()
    {
        $before = $this->author_rows();
        $author = Author::find(1);
        $author->author_id = null;
        $author->name = 'lost';

        $this->assert_refused(
            'Cannot update, primary key value is null for: Author (author_id)',
            fn() => $author->save()
        );
        $this->assert_same($before, $this->author_rows());
    }

    public function test_delete_with_a_nulled_pk_is_refused()
    {
        $author = Author::find(1);
        $author->author_id = null;

        $this->assert_refused(
            'Cannot delete, primary key value is null for: Author (author_id)',
            fn() => $author->delete()
        );
        $this->assert_true(Author::exists(1));
    }

    public function test_save_with_a_changed_pk_is_refused()
    {
        $before = $this->author_rows();
        $author = Author::find(1);
        $author->author_id = 2;
        $author->name = 'overwrites author 2';

        $this->assert_refused(
            'Cannot update, primary key changed for: Author (author_id: 1 => 2)',
            fn() => $author->save()
        );
        $this->assert_same($before, $this->author_rows());
    }

    public function test_update_attribute_with_a_changed_pk_is_refused()
    {
        $before = $this->author_rows();
        $author = Author::find(1);
        $author->id = 2; // the id shortcut resolves to author_id

        $this->assert_refused(
            'Cannot update, primary key changed for: Author (author_id: 1 => 2)',
            fn() => $author->update_attribute('name', 'overwrites author 2')
        );

        $author = Author::find(1);
        $this->assert_refused(
            'Cannot update, primary key changed for: Author (author_id: 1 => 3)',
            fn() => $author->update_attribute('author_id', '3')
        );
        $this->assert_same($before, $this->author_rows());
    }

    public function test_update_attributes_with_a_changed_pk_is_refused()
    {
        $before = $this->author_rows();
        $author = Author::find(1);

        $this->assert_refused(
            'Cannot update, primary key changed for: Author (author_id: 1 => 2)',
            fn() => $author->update_attributes(['author_id' => '2', 'name' => 'overwrites author 2'])
        );
        $this->assert_same($before, $this->author_rows());
    }

    public function test_delete_with_a_changed_pk_is_refused()
    {
        $author = Author::find(1);
        $author->author_id = 2;

        $this->assert_refused(
            'Cannot delete, primary key changed for: Author (author_id: 1 => 2)',
            fn() => $author->delete()
        );
        $this->assert_true(Author::exists(1));
        $this->assert_true(Author::exists(2));
    }

    public function test_pk_changed_through_an_alias_is_refused()
    {
        $book = BookAttrProtected::find(1);
        $book->protected_pk_alias = 2;

        $this->assert_refused(
            'Cannot update, primary key changed for: BookAttrProtected (book_id: 1 => 2)',
            fn() => $book->save(),
            BookAttrProtected::class
        );
        $this->assert_same('Another Book', Book::find(2)->name);
    }

    public function test_composite_pk_nulled_or_changed_is_refused()
    {
        $receipt = PkGuardReadReceipt::first(['conditions' => ['user_id' => 1, 'story_id' => 1]]);
        $receipt->story_id = null;
        $this->assert_refused(
            'Cannot update, primary key value is null for: PkGuardReadReceipt (story_id)',
            fn() => $receipt->save(),
            PkGuardReadReceipt::class
        );

        // (3, 1) exists: a DELETE keyed on the changed values would remove it
        $receipt = PkGuardReadReceipt::first(['conditions' => ['user_id' => 1, 'story_id' => 1]]);
        $receipt->user_id = 3;
        $this->assert_refused(
            'Cannot delete, primary key changed for: PkGuardReadReceipt (user_id: 1 => 3)',
            fn() => $receipt->delete(),
            PkGuardReadReceipt::class
        );
        $this->assert_equals(3, PkGuardReadReceipt::count());

        // unchanged composite keys write normally
        $receipt = PkGuardReadReceipt::first(['conditions' => ['user_id' => 2, 'story_id' => 2]]);
        $receipt->user_id = '2';
        $this->assert_true($receipt->save());
        $this->assert_true($receipt->delete());
        $this->assert_equals(0, PkGuardReadReceipt::count(['conditions' => ['user_id' => 2]]));
        $this->assert_equals(2, PkGuardReadReceipt::count());
    }

    public function test_readonly_is_reported_before_the_pk()
    {
        $author = Author::first(['readonly' => true]);
        $author->author_id = null;

        $this->expectException(ReadOnlyException::class);
        $author->save();
    }

    public function test_reassigning_the_same_pk_value_is_not_a_change()
    {
        $author = Author::find(1);
        $author->author_id = 1;
        $author->name = 'same int';
        $this->assert_true($author->save());
        $this->assert_same('SAME INT', Author::find(1)->name);

        $author->id = '1'; // cast to 1 by the column
        $this->assert_true($author->update_attribute('name', 'same string'));
        $this->assert_same('SAME STRING', Author::find(1)->name);

        $this->assert_true($author->update_attributes(['author_id' => '1', 'name' => 'mass assigned']));
        $this->assert_same('MASS ASSIGNED', Author::find(1)->name);

        $author->author_id = 1;
        $this->assert_true($author->delete());
        $this->assert_false(Author::exists(1));
    }

    public function test_pk_changed_back_to_its_value_is_not_a_change()
    {
        $author = Author::find(1);
        $author->author_id = 2;
        $author->author_id = 1;
        $author->name = 'back';

        $this->assert_true($author->save());
        $this->assert_same(['1=BACK', '2=George W. Bush', '3=Bill Clinton', '4=Uncle Bob'], $this->author_rows());
    }

    public function test_inserted_record_updates_and_deletes_normally()
    {
        $author = Author::create(['name' => 'fresh']);
        $id = (int) $author->author_id;

        $author->author_id = $id; // the generated key may come back as a string
        $author->name = 'updated';
        $this->assert_true($author->save());
        $this->assert_same('UPDATED', Author::find($id)->name);

        $this->assert_true($author->update_attribute('name', 'again'));
        $this->assert_same('AGAIN', Author::find($id)->name);

        $this->assert_true($author->delete());
        $this->assert_false(Author::exists($id));
    }

    public function test_record_inserted_with_an_explicit_pk_updates_normally()
    {
        $author = Author::create(['author_id' => 9999, 'name' => 'explicit']);
        $author->name = 'updated';

        $this->assert_true($author->save());
        $this->assert_same('UPDATED', Author::find(9999)->name);

        $author->author_id = 1;
        $this->assert_refused(
            'Cannot update, primary key changed for: Author (author_id: 9999 => 1)',
            fn() => $author->save()
        );
    }

    public function test_reload_takes_the_reloaded_pk_as_persisted()
    {
        // the row is renamed behind the record's back; pointing the record at
        // it and reloading makes the new key the persisted one
        $author = Author::find(1);
        Author::update_all(['set' => ['author_id' => 50], 'conditions' => ['author_id' => 1]]);
        $author->author_id = 50;
        $author->reload();

        $author->name = 'reloaded';
        $this->assert_true($author->save());
        $this->assert_same('RELOADED', Author::find(50)->name);
    }

    public function test_clone_keeps_the_persisted_pk()
    {
        $clone = clone Author::find(1);
        $clone->name = 'cloned';
        $this->assert_true($clone->save());
        $this->assert_same('CLONED', Author::find(1)->name);

        $clone->author_id = 2;
        $this->assert_refused(
            'Cannot update, primary key changed for: Author (author_id: 1 => 2)',
            fn() => $clone->save()
        );
    }

    public function test_unserialized_record_keeps_the_persisted_pk()
    {
        $copy = unserialize(serialize(Author::find(1)));
        $copy->name = 'woken up';
        $this->assert_true($copy->save());
        $this->assert_same('WOKEN UP', Author::find(1)->name);

        $copy->author_id = 2;
        $this->assert_refused(
            'Cannot delete, primary key changed for: Author (author_id: 1 => 2)',
            fn() => $copy->delete()
        );
    }

    public function test_record_serialized_before_pk_tracking_saves_normally()
    {
        // a payload cached by an older version has no tracking property:
        // rebuild one from the record's properties without it
        $properties = (array) Author::find(1);
        $tracking = "\0" . Model::class . "\0__persisted_pk";
        $this->assert_true(array_key_exists($tracking, $properties));
        unset($properties[$tracking]);
        $legacy = unserialize('O:' . strlen(Author::class) . ':"' . Author::class . '"' . substr(serialize($properties), 1));

        $legacy->name = 'legacy';
        $this->assert_true($legacy->save());
        $this->assert_same('LEGACY', Author::find(1)->name);

        $legacy->author_id = 2;
        $this->assert_refused(
            'Cannot update, primary key changed for: Author (author_id: 1 => 2)',
            fn() => $legacy->save()
        );
        $legacy->author_id = 1;
        $this->assert_true($legacy->delete());
        $this->assert_false(Author::exists(1));
    }

    public function test_before_update_callback_renaming_the_pk_keeps_the_record_saveable()
    {
        // the UPDATE is keyed on the pk read before before_update runs, so a
        // callback assigning a new pk renames the row (pre-existing behavior)
        Author::table()->callback->register('before_update', function (Author $author) {
            $author->author_id = 100;
        });

        $author = Author::find(1);
        $author->name = 'renamed';
        $this->assert_true($author->save());
        $this->assert_false(Author::exists(1));
        $this->assert_same('RENAMED', Author::find(100)->name);

        $author->name = 'saved again';
        $this->assert_true($author->save());
        $this->assert_same('SAVED AGAIN', Author::find(100)->name);
    }

    public function test_new_record_with_an_explicit_pk_still_writes_by_that_key()
    {
        // pinned pre-existing behavior: only a NULL pk is refused on a new record
        $author = new Author(['author_id' => 2]);
        $this->assert_true($author->update_attribute('name', 'by key'));
        $this->assert_same('BY KEY', Author::find(2)->name);

        $this->assert_true((new Author(['author_id' => 2]))->delete());
        $this->assert_false(Author::exists(2));
    }

    public function test_eager_loaded_and_joined_records_save_normally()
    {
        $book = Author::find(1, ['include' => ['books']])->books[0];
        $book->name = 'eager';
        $this->assert_true($book->save());
        $this->assert_same('eager', Book::find(1)->name);

        $book = Book::first([
            'select' => 'books.*, authors.name AS author_name',
            'joins' => ['author'],
            'conditions' => ['books.book_id = ?', 2],
        ]);
        $book->name = 'joined';
        $this->assert_true($book->save());
        $this->assert_same('joined', Book::find(2)->name);
    }

    public function test_record_created_through_an_association_updates_normally()
    {
        $book = Author::find(1)->create_book(['name' => 'via association']);
        $book->name = 'renamed';

        $this->assert_true($book->save());
        $this->assert_same('renamed', Book::find($book->id)->name);
    }

    public function test_guard_dropping_the_pk_from_mass_assignment_saves_normally()
    {
        $book = BookAttrProtected::find(1);

        $this->assert_true($book->update_attributes(['book_id' => 2, 'secondary_author_id' => 3]));
        $this->assert_same(3, Book::find(1)->secondary_author_id);
        $this->assert_same('Another Book', Book::find(2)->name);
    }

    public function test_record_loaded_without_its_pk_is_unchanged()
    {
        // pinned pre-existing behavior: the missing pk is reported by values_for_pk()
        $author = Author::first(['select' => 'name', 'order' => 'author_id']);
        $this->assert_true($author->save()); // nothing dirty: nothing to write

        $author->name = 'no pk';
        $this->assert_exception_message_contains('Undefined property: Author->author_id', fn() => $author->save());
        $this->assert_exception_message_contains('Undefined property: Author->author_id', fn() => $author->delete());
        $this->assert_same('Tito', Author::find(1)->name);
    }

    public function test_stringable_pk_assigned_in_before_create_updates_and_deletes()
    {
        $item = PkGuardGeneratedCodeItem::create(['owner' => 'acme', 'name' => 'new']);
        $this->assert_instance_of(PkGuardCodeValue::class, $item->code);

        $item->name = 'saved';
        $this->assert_true($item->save());
        $this->assert_true($item->update_attribute('name', 'updated'));
        $this->assert_same('updated', $this->coded_item_name('acme', 'generated'));

        $this->assert_true($item->delete());
        $this->assert_null($this->coded_item_name('acme', 'generated'));
    }

    public function test_stringable_pk_assigned_by_hand_updates_normally()
    {
        $item = new PkGuardCodedItem();
        $item->owner = 'acme';
        $item->code = new PkGuardCodeValue('manual');
        $item->name = 'new';
        $this->assert_true($item->save());

        $this->assert_true($item->update_attribute('name', 'updated'));
        $this->assert_same('updated', $this->coded_item_name('acme', 'manual'));
    }

    public function test_equal_stringable_pk_on_a_loaded_record_is_not_a_change()
    {
        $item = PkGuardCodedItem::first(['conditions' => ['owner' => 'acme', 'code' => 'abc']]);
        $item->code = new PkGuardCodeValue('abc');
        $item->name = 'same';

        $this->assert_true($item->save());
        $this->assert_same('same', $this->coded_item_name('acme', 'abc'));
    }

    public function test_different_stringable_pk_is_a_change()
    {
        $item = PkGuardCodedItem::first(['conditions' => ['owner' => 'acme', 'code' => 'abc']]);
        $item->code = new PkGuardCodeValue('def');
        $item->name = 'overwrites def';

        $this->assert_refused(
            "Cannot update, primary key changed for: PkGuardCodedItem (code: 'abc' => 'def')",
            fn() => $item->save(),
            PkGuardCodedItem::class
        );
        $this->assert_same('one', $this->coded_item_name('acme', 'abc'));
        $this->assert_same('two', $this->coded_item_name('acme', 'def'));
    }

    public function test_pk_filled_by_the_database_on_insert_is_not_tracked()
    {
        // `code` is not sent, so the database fills it; the attribute keeps the
        // introspected default expression, which is not what the row is stored under
        $item = PkGuardCodedItem::create(['owner' => 'db', 'name' => 'new']);

        // the usual workaround: read the generated key back and assign it
        $item->code = PkGuardCodedItem::first(['conditions' => ['owner' => 'db']])->code;
        $item->name = 'updated';
        $this->assert_true($item->save());
        $this->assert_same('updated', PkGuardCodedItem::first(['conditions' => ['owner' => 'db']])->name);

        // the column the INSERT sent is still tracked, and a null value is still refused
        $item->owner = 'other';
        $this->assert_refused(
            "Cannot update, primary key changed for: PkGuardCodedItem (owner: 'db' => 'other')",
            fn() => $item->save(),
            PkGuardCodedItem::class
        );

        $item->owner = 'db';
        $item->code = null;
        $this->assert_refused(
            'Cannot delete, primary key value is null for: PkGuardCodedItem (code)',
            fn() => $item->delete(),
            PkGuardCodedItem::class
        );
        $this->assert_equals(1, PkGuardCodedItem::count(['conditions' => ['owner' => 'db']]));
    }

    public function test_record_built_as_persisted_tracks_only_the_pk_values_passed_in()
    {
        // `code` is not passed: the attribute holds the column default, which is not
        // what the row is stored under, so assigning the real key is not a change
        $item = new PkGuardCodedItem(['owner' => 'acme', 'name' => 'renamed'], true, false, false);
        $item->code = 'abc';
        $this->assert_true($item->save());
        $this->assert_same('renamed', $this->coded_item_name('acme', 'abc'));

        $item->owner = 'other';
        $this->assert_refused(
            "Cannot update, primary key changed for: PkGuardCodedItem (owner: 'acme' => 'other')",
            fn() => $item->save(),
            PkGuardCodedItem::class
        );
    }

    /**
     * @return list<int> hits of every dated_counts row, in pk order
     */
    private function dated_hits(): array
    {
        return array_map(fn(PkGuardDatedCount $c) => (int) $c->hits, PkGuardDatedCount::all(['order' => 'day, at']));
    }

    public function test_date_and_datetime_pk_values_match_their_row_on_update()
    {
        // the UPDATE's WHERE bound them through DateTime::__toString() (RFC 2822): MySQL
        // refused it (error 1292), SQLite and MariaDB matched no row and returned true
        $count = PkGuardDatedCount::first(['conditions' => ['hits' => 1]]);
        $count->hits = 10;
        $this->assert_true($count->save());
        $this->assert_same([10, 2], $this->dated_hits());

        $this->assert_true($count->update_attribute('hits', 11));
        $this->assert_same([11, 2], $this->dated_hits());
    }

    public function test_created_date_and_datetime_pk_updates_and_deletes()
    {
        $count = PkGuardDatedCount::create(['day' => '2026-03-01', 'at' => new \DateTime('2026-03-01 08:00:00'), 'hits' => 3]);

        $this->assert_true($count->update_attribute('hits', 5));
        $this->assert_same([1, 2, 5], $this->dated_hits());

        $this->assert_true($count->delete());
        $this->assert_same([1, 2], $this->dated_hits());
    }

    public function test_datetime_immutable_pk_values_are_bound_like_datetimes()
    {
        // an equal DateTimeImmutable is the same key; it reaches the SET and the WHERE,
        // where PDO could not bind it ("could not be converted to string")
        $count = PkGuardDatedCount::first(['conditions' => ['hits' => 1]]);
        $count->at = new \DateTimeImmutable('2026-01-01 10:00:00');
        $count->hits = 12;
        $this->assert_true($count->save());
        $this->assert_same([12, 2], $this->dated_hits());

        $count = PkGuardDatedCount::first(['conditions' => ['hits' => 12]]);
        $count->day = new \DateTimeImmutable('2026-01-01');
        $this->assert_true($count->delete());
        $this->assert_same([2], $this->dated_hits());
    }

    private function seen_at(string $day): ?string
    {
        return PkGuardDatedCount::first(['conditions' => ['day' => $day]])?->seen_at?->format('Y-m-d H:i:s');
    }

    public function test_datetime_immutable_values_are_bound_in_an_insert()
    {
        // process_data() formatted only \DateTime: any other DateTimeInterface reached PDO
        // as an object ("could not be converted to string"). Assigned one by one: mass
        // assignment casts a date column's value and does not accept a DateTimeImmutable
        $count = new PkGuardDatedCount();
        $count->day = new \DateTimeImmutable('2026-03-01');
        $count->at = new \DateTimeImmutable('2026-03-01 08:00:00');
        $count->seen_at = new \DateTimeImmutable('2026-03-01 09:30:00');
        $count->hits = 3;
        $this->assert_true($count->save());
        $this->assert_same([1, 2, 3], $this->dated_hits());
        $this->assert_same('2026-03-01 09:30:00', $this->seen_at('2026-03-01'));
    }

    public function test_datetime_immutable_value_is_bound_in_an_update_set()
    {
        $count = PkGuardDatedCount::first(['conditions' => ['hits' => 2]]);
        $count->seen_at = new \DateTimeImmutable('2026-03-02 11:00:00');
        $this->assert_true($count->save());
        $this->assert_same('2026-03-02 11:00:00', $this->seen_at('2026-01-02'));
    }

    public function test_datetime_immutable_values_are_bound_in_finders()
    {
        $found = PkGuardDatedCount::first(['conditions' => ['at' => new \DateTimeImmutable('2026-01-02 10:00:00')]]);
        $this->assert_equals(2, $found?->hits);

        $found = PkGuardDatedCount::find_by_sql('SELECT * FROM dated_counts WHERE at = ?', [new \DateTimeImmutable('2026-01-01 10:00:00')]);
        $this->assert_same([1], array_map(fn(PkGuardDatedCount $c) => (int) $c->hits, $found));
    }

    public function test_datetime_immutable_values_are_bound_in_an_upsert()
    {
        PkGuardDatedCount::upsert([['day' => new \DateTimeImmutable('2026-01-01'),
            'at' => new \DateTimeImmutable('2026-01-01 10:00:00'), 'hits' => 7]], ['day', 'at']);
        $this->assert_same([7, 2], $this->dated_hits());
    }

    public function test_reload_of_a_composite_pk_record_reloads_its_row()
    {
        // reload() found by the first pk column with every pk value ("owner IN ('acme', 'def')"):
        // RecordNotFound (found N, but was looking for 2)
        $item = PkGuardCodedItem::first(['conditions' => ['owner' => 'acme', 'code' => 'def']]);
        PkGuardCodedItem::update_all(['set' => ['name' => 'external'], 'conditions' => ['owner' => 'acme', 'code' => 'def']]);
        $item->name = 'dirty';

        $this->assert_same($item, $item->reload());
        $this->assert_same('external', $item->name);
        $this->assert_false($item->is_dirty());

        $this->assert_true($item->update_attribute('name', 'saved'));
        $this->assert_same('saved', $this->coded_item_name('acme', 'def'));
        $this->assert_same('one', $this->coded_item_name('acme', 'abc'));

        $receipt = PkGuardReadReceipt::first(['conditions' => ['user_id' => 2, 'story_id' => 2]]);
        $this->assert_same($receipt, $receipt->reload());
        $this->assert_equals(2, $receipt->user_id);
    }

    public function test_reload_of_a_deleted_composite_pk_row_throws_record_not_found()
    {
        $item = PkGuardCodedItem::first(['conditions' => ['owner' => 'acme', 'code' => 'abc']]);
        PkGuardCodedItem::delete_all(['conditions' => ['owner' => 'acme', 'code' => 'abc']]);

        try {
            $item->reload();
            $this->fail('expected RecordNotFound');
        } catch (RecordNotFound $e) {
            $this->assert_same("Couldn't find PkGuardCodedItem with ID=acme,abc", $e->getMessage());
        }
    }

    public function test_reload_of_a_single_pk_record_is_unchanged()
    {
        $author = Author::find(1);
        $author->author_id = 99;

        try {
            $author->reload();
            $this->fail('expected RecordNotFound');
        } catch (RecordNotFound $e) {
            $this->assert_same("Couldn't find Author with ID=99", $e->getMessage());
        }
    }

    public function test_find_by_a_date_pk_and_reload_match_the_row()
    {
        // a find by pk binds its values positionally, in the datetime format: SQLite compared
        // '2026-01-02 00:00:00' with the stored '2026-01-02' and found nothing
        $day = PkGuardDatedDay::find(new \DateTime('2026-01-02'));
        $this->assert_equals(2, $day->hits);

        PkGuardDatedDay::update_all(['set' => ['hits' => 8], 'conditions' => ['hits' => 2]]);
        $this->assert_same($day, $day->reload());
        $this->assert_equals(8, $day->hits);

        $this->assert_count(2, PkGuardDatedDay::find([new \DateTime('2026-01-01'), new \DateTime('2026-01-02')]));
    }

    public function test_reload_of_a_composite_date_pk_record_matches_the_row()
    {
        $count = PkGuardDatedCount::first(['conditions' => ['hits' => 1]]);
        PkGuardDatedCount::update_all(['set' => ['hits' => 9], 'conditions' => ['hits' => 1]]);

        $this->assert_same($count, $count->reload());
        $this->assert_equals(9, $count->hits);
        $this->assert_true($count->update_attribute('hits', 10));
        $this->assert_same([10, 2], $this->dated_hits());
    }

    public function test_writes_on_a_deleted_record_are_refused()
    {
        // after delete() the record stayed persisted: a dirty save() ran an UPDATE on the
        // deleted row and returned true, and so did update_attribute() and a second delete()
        $author = Author::find(1);
        $this->assert_true($author->delete());
        $this->assert_false($author->is_new_record());

        $author->name = 'ghost';
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $author->save());
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $author->update_attribute('name', 'ghost'));
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $author->update_attributes(['name' => 'ghost']));
        $this->assert_refused('Cannot delete, record has been deleted: Author', fn() => $author->delete());

        $clean = Author::find(2);
        $clean->delete();
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $clean->save());

        $this->assert_false(Author::exists(1));
        $this->assert_same(['3=Bill Clinton', '4=Uncle Bob'], $this->author_rows());
    }

    public function test_cancelled_delete_does_not_mark_the_record_deleted()
    {
        Author::table()->callback->register('before_destroy', fn() => false);
        $author = Author::find(1);

        $this->assert_false($author->delete());
        $this->assert_true($author->update_attribute('name', 'kept'));
        $this->assert_same('KEPT', Author::find(1)->name);
    }

    public function test_a_clone_of_a_deleted_record_is_deleted_and_a_new_load_is_not()
    {
        $author = Author::find(1);
        $author->delete();
        $clone = clone $author;
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $clone->update_attribute('name', 'ghost'));

        $again = Author::create(['author_id' => 1, 'name' => 'again']);
        $this->assert_true($again->update_attribute('name', 'again and again'));
        $this->assert_true(Author::find(1)->delete());

        // built by hand as persisted: not the deleted object, so it writes by its key
        $built = new Author(['author_id' => 2, 'name' => 'built'], true, false, false);
        $this->assert_true($built->save());
        $this->assert_same('BUILT', Author::find(2)->name);
    }

    public function test_reload_of_a_deleted_record_clears_the_flag_when_the_row_exists_again()
    {
        $author = Author::find(1);
        $author->delete();

        try {
            $author->reload();
            $this->fail('expected RecordNotFound');
        } catch (RecordNotFound $e) {
            $this->assert_same("Couldn't find Author with ID=1", $e->getMessage());
        }

        Author::create(['author_id' => 1, 'name' => 'restored']);
        $this->assert_same($author, $author->reload());
        $this->assert_same('RESTORED', $author->name);
        $this->assert_true($author->update_attribute('name', 'saved'));
        $this->assert_same('SAVED', Author::find(1)->name);
    }

    public function test_deleted_flag_survives_serialization_and_a_legacy_payload_is_not_deleted()
    {
        $author = Author::find(1);
        $author->delete();
        $copy = unserialize(serialize($author));
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $copy->update_attribute('name', 'ghost'));

        // a payload cached by an older version has no flag: not deleted, as before
        $properties = (array) Author::find(2);
        $flag = "\0" . Model::class . "\0__destroyed";
        $this->assert_true(array_key_exists($flag, $properties));
        unset($properties[$flag]);
        $legacy = unserialize('O:' . strlen(Author::class) . ':"' . Author::class . '"' . substr(serialize($properties), 1));
        $this->assert_true($legacy->update_attribute('name', 'legacy'));
        $this->assert_same('LEGACY', Author::find(2)->name);
    }

    public function test_delete_all_does_not_touch_loaded_records()
    {
        // static, no instances: a record loaded before keeps writing by its key (matching nothing)
        $author = Author::find(1);
        Author::delete_all(['conditions' => ['author_id' => 1]]);
        $this->assert_true($author->update_attribute('name', 'ghost'));
        $this->assert_false(Author::exists(1));
    }

    public function test_delete_rolled_back_by_returning_false_is_undone_on_the_record()
    {
        $author = Author::find(1);
        $this->assert_false(Author::transaction(function () use ($author) {
            $this->assert_true($author->delete());
            $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $author->update_attribute('name', 'ghost'));

            return false;
        }));

        $this->assert_true(Author::exists(1));
        $author->name = 'kept';
        $this->assert_true($author->save());
        $this->assert_same('KEPT', Author::find(1)->name);
    }

    public function test_delete_rolled_back_by_an_exception_is_undone_on_the_record()
    {
        $author = Author::find(1);

        try {
            Author::transaction(function () use ($author) {
                $author->delete();

                throw new RuntimeException('abort');
            });
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assert_same('abort', $e->getMessage());
        }

        $this->assert_true(Author::exists(1));
        $this->assert_true($author->delete());
        $this->assert_false(Author::exists(1));
    }

    public function test_committed_delete_stays_deleted()
    {
        $author = Author::find(1);
        $this->assert_true(Author::transaction(fn() => $author->delete()));

        $this->assert_false(Author::exists(1));
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $author->update_attribute('name', 'ghost'));
    }

    public function test_nested_rollback_restores_only_the_records_deleted_in_its_savepoint()
    {
        [$outer, $rolled_back, $committed] = [Author::find(1), Author::find(2), Author::find(3)];

        $this->assert_true(Author::transaction(function () use ($outer, $rolled_back, $committed) {
            $outer->delete();
            $this->assert_false(Author::transaction(function () use ($rolled_back) {
                $rolled_back->delete();

                return false;
            }));
            $this->assert_true(Author::transaction(fn() => $committed->delete()));

            // the savepoint rolled back: that record is saveable again inside the outer scope
            $this->assert_true($rolled_back->update_attribute('name', 'restored'));
            $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $committed->update_attribute('name', 'ghost'));
        }));

        $this->assert_same(['2=RESTORED', '4=Uncle Bob'], $this->author_rows());
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $outer->update_attribute('name', 'ghost'));
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $committed->update_attribute('name', 'ghost'));
    }

    public function test_outer_rollback_restores_records_deleted_in_committed_inner_scopes()
    {
        [$outer, $inner] = [Author::find(1), Author::find(3)];

        $this->assert_false(Author::transaction(function () use ($outer, $inner) {
            $outer->delete();
            $this->assert_true(Author::transaction(fn() => $inner->delete()));

            return false;
        }));

        $this->assert_same(4, count($this->author_rows()));
        $this->assert_true($outer->update_attribute('name', 'outer'));
        $this->assert_true($inner->update_attribute('name', 'inner'));
        $this->assert_same(['1=OUTER', '2=George W. Bush', '3=INNER', '4=Uncle Bob'], $this->author_rows());
    }

    public function test_date_pk_created_with_a_time_of_day_is_found_and_reloaded()
    {
        // the DATE column stores 2026-05-05; a find by pk and reload() bound the
        // value's time too and missed the row (RecordNotFound, or an Error from the message)
        $day = PkGuardDatedDay::create(['day' => new \DateTime('2026-05-05 14:00:00'), 'at' => '2026-05-05 14:00:00', 'hits' => 5]);

        $this->assert_same($day, $day->reload());
        $this->assert_equals(5, $day->hits);
        $this->assert_equals(5, PkGuardDatedDay::find(new \DateTime('2026-05-05 14:00:00'))->hits);
        $this->assert_equals(5, PkGuardDatedDay::find(new \DateTimeImmutable('2026-05-05 23:59:59'))->hits);
        $this->assert_true($day->update_attribute('hits', 6));
        $this->assert_same([1, 2, 6], $this->dated_hits());
    }

    public function test_find_by_pk_not_found_message_formats_date_values()
    {
        $messages = [];

        foreach ([new \DateTime('2030-01-01'), new \DateTimeImmutable('2030-01-01'), new ActiveRecord\DateTime('2030-01-01'), [new \DateTime('2026-01-01'), new \DateTime('2030-01-01')]] as $value) {
            try {
                PkGuardDatedDay::find($value);
                $this->fail('expected RecordNotFound');
            } catch (RecordNotFound $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assert_same([
            "Couldn't find PkGuardDatedDay with ID=2030-01-01 00:00:00",
            "Couldn't find PkGuardDatedDay with ID=2030-01-01 00:00:00",
            // ActiveRecord\DateTime is Stringable: its text is unchanged
            "Couldn't find PkGuardDatedDay with ID=" . new ActiveRecord\DateTime('2030-01-01'),
            "Couldn't find all PkGuardDatedDay with IDs (2026-01-01 00:00:00,2030-01-01 00:00:00) (found 1, but was looking for 2)",
        ], $messages);
    }

    public function test_datetime_immutable_values_are_accepted_by_mass_assignment()
    {
        // Column::cast() turned only a \DateTime into an ActiveRecord\DateTime and passed a
        // DateTimeImmutable to date_create(): TypeError
        $rome = new \DateTimeZone('Europe/Rome');
        $count = PkGuardDatedCount::create(['day' => new \DateTimeImmutable('2026-03-01'),
            'at' => new \DateTimeImmutable('2026-03-01 08:00:00'), 'hits' => 3,
            'seen_at' => new \DateTimeImmutable('2026-03-01 09:30:00', $rome)]);
        $this->assert_instance_of(ActiveRecord\DateTime::class, $count->seen_at);
        $this->assert_same('2026-03-01 09:30:00 CET', $count->seen_at->format('Y-m-d H:i:s T'));
        $this->assert_same([1, 2, 3], $this->dated_hits());
        $this->assert_same('2026-03-01 09:30:00', $this->seen_at('2026-03-01'));

        $built = new PkGuardDatedCount(['day' => new \DateTimeImmutable('2026-04-01'), 'at' => new \DateTimeImmutable('2026-04-01 08:00:00'), 'hits' => 4]);
        $this->assert_instance_of(ActiveRecord\DateTime::class, $built->day);
        $this->assert_true($built->save());

        $this->assert_true($count->update_attributes(['seen_at' => new \DateTimeImmutable('2026-03-02 10:00:00')]));
        $this->assert_same('2026-03-02 10:00:00', $this->seen_at('2026-03-01'));
        $this->assert_same([1, 2, 3, 4], $this->dated_hits());
    }

    public function test_delete_of_another_model_in_a_rolled_back_scope_is_undone()
    {
        // same connection, other class: the scope is the connection's
        $book = Book::find(1);
        $this->assert_false(Author::transaction(function () use ($book) {
            $book->delete();

            return false;
        }));

        $this->assert_true($book->update_attribute('name', 'kept'));
        $this->assert_same('kept', Book::find(1)->name);
    }

    public function test_inner_exception_caught_by_a_committed_outer_scope_restores_only_the_inner_record()
    {
        [$outer, $inner] = [Author::find(1), Author::find(3)];

        $this->assert_true(Author::transaction(function () use ($outer, $inner) {
            $outer->delete();

            try {
                Author::transaction(function () use ($inner) {
                    $inner->delete();

                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException $e) {
                $this->assert_same('inner', $e->getMessage());
            }
        }));

        $this->assert_same(['2=George W. Bush', '3=Bill Clinton', '4=Uncle Bob'], $this->author_rows());
        $this->assert_true($inner->update_attribute('name', 'inner'));
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $outer->update_attribute('name', 'ghost'));
    }

    public function test_after_destroy_throwing_inside_a_transaction_restores_the_record()
    {
        Author::table()->callback->register('after_destroy', function () {
            throw new RuntimeException('after_destroy failed');
        });
        $author = Author::find(1);

        try {
            Author::transaction(fn() => $author->delete());
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assert_same('after_destroy failed', $e->getMessage());
        }

        $this->assert_true(Author::exists(1));
        $this->assert_true($author->update_attribute('name', 'kept'));

        // outside a transaction the DELETE stands: the record stays deleted
        try {
            $author->delete();
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
        }
        $this->assert_false(Author::exists(1));
        $this->assert_refused('Cannot update, record has been deleted: Author', fn() => $author->update_attribute('name', 'ghost'));
    }
}
