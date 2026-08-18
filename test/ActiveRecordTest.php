<?php

class ActiveRecordTest extends DatabaseTest
{
    /** @var array */
    protected $options;

    /** @var Psr\Log\LoggerInterface|null */
    private $original_logger;

    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);
        $this->options = ['conditions' => 'blah', 'order' => 'blah'];
    }

    public function tear_down()
    {
        if ($this->original_logger) {
            ActiveRecord\Config::instance()->set_logger($this->original_logger);
            $this->original_logger = null;
        }
        parent::tear_down();
    }

    /**
     * Swaps a record-capturing logger into the global Config; the suite's
     * logger is put back in tear_down(). Only Config is swapped — existing
     * Connection objects keep the logger they grabbed at connect time.
     *
     * @return Psr\Log\AbstractLogger&object{records: list<array{level: string, message: string}>}
     */
    private function capture_log()
    {
        $config = ActiveRecord\Config::instance();
        $this->original_logger = $config->get_logger();

        $logger = new class extends Psr\Log\AbstractLogger {
            /** @var list<array{level: string, message: string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
        $config->set_logger($logger);

        return $logger;
    }

    public function test_options_is_not()
    {
        $this->assert_false(Author::is_options_hash(null));
        $this->assert_false(Author::is_options_hash(''));
        $this->assert_false(Author::is_options_hash('tito'));
        $this->assert_false(Author::is_options_hash([]));
        $this->assert_false(Author::is_options_hash([1,2,3]));
    }

    public function test_options_hash_with_unknown_keys()
    {
        $this->expectException(ActiveRecord\ActiveRecordException::class);
        $this->assert_false(Author::is_options_hash(['conditions' => 'blah', 'sharks' => 'laserz', 'dubya' => 'bush']));
    }

    public function test_options_is_hash()
    {
        $this->assert_true(Author::is_options_hash($this->options));
    }

    public function test_extract_and_validate_options()
    {
        $args = ['first',$this->options];
        $this->assert_equals($this->options, Author::extract_and_validate_options($args));
        $this->assert_equals(['first'], $args);
    }

    public function test_extract_and_validate_options_with_array_in_args()
    {
        $args = ['first',[1,2],$this->options];
        $this->assert_equals($this->options, Author::extract_and_validate_options($args));
    }

    public function test_extract_and_validate_options_removes_options_hash()
    {
        $args = ['first',$this->options];
        Author::extract_and_validate_options($args);
        $this->assert_equals(['first'], $args);
    }

    public function test_extract_and_validate_options_nope()
    {
        $args = ['first'];
        $this->assert_equals([], Author::extract_and_validate_options($args));
        $this->assert_equals(['first'], $args);
    }

    public function test_extract_and_validate_options_nope_because_wasnt_at_end()
    {
        $args = ['first',$this->options,[1,2]];
        $this->assert_equals([], Author::extract_and_validate_options($args));
    }

    public function test_invalid_attribute()
    {
        $this->expectException(ActiveRecord\UndefinedPropertyException::class);
        $author = Author::find('first', ['conditions' => 'author_id=1']);
        $author->some_invalid_field_name;
    }

    public function test_invalid_attributes()
    {
        $book = Book::find(1);
        try {
            $book->update_attributes(['name' => 'new name', 'invalid_attribute' => true, 'another_invalid_attribute' => 'something']);
        } catch (ActiveRecord\UndefinedPropertyException $e) {
            $exceptions = explode("\r\n", $e->getMessage());
        }

        $this->assert_equals(1, substr_count($exceptions[0], 'invalid_attribute'));
        $this->assert_equals(1, substr_count($exceptions[1], 'another_invalid_attribute'));
    }

    public function test_getter_undefined_property_exception_includes_model_name()
    {
        $this->assert_exception_message_contains("Author->this_better_not_exist", function () {
            $author = new Author();
            $author->this_better_not_exist;
        });
    }

    public function test_mass_assignment_undefined_property_exception_includes_model_name()
    {
        $this->assert_exception_message_contains("Author->this_better_not_exist", function () {
            new Author(["this_better_not_exist" => "hi"]);
        });
    }

    public function test_setter_undefined_property_exception_includes_model_name()
    {
        $this->assert_exception_message_contains("Author->this_better_not_exist", function () {
            $author = new Author();
            $author->this_better_not_exist = "hi";
        });
    }

    public function test_get_values_for()
    {
        $book = Book::find_by_name('Ancient Art of Main Tanking');
        $ret = $book->get_values_for(['book_id','author_id']);
        $this->assert_equals(['book_id','author_id'], array_keys($ret));
        $this->assert_equals([1,1], array_values($ret));
    }

    public function test_hyphenated_column_names_to_underscore()
    {
        $keys = array_keys(RmBldg::first()->attributes());
        $this->assert_true(in_array('rm_name', $keys));
    }

    public function test_column_names_with_spaces()
    {
        $keys = array_keys(RmBldg::first()->attributes());
        $this->assert_true(in_array('space_out', $keys));
    }

    public function test_mixed_case_column_name()
    {
        $keys = array_keys(Author::first()->attributes());
        $this->assert_true(in_array('mixedcasefield', $keys));
    }

    public function test_mixed_case_primary_key_save()
    {
        $venue = Venue::find(1);
        $venue->name = 'should not throw exception';
        $venue->save();
        $this->assert_equals($venue->name, Venue::find(1)->name);
    }

    public function test_reload()
    {
        $venue = Venue::find(1);
        $this->assert_equals('NY', $venue->state);
        $venue->state = 'VA';
        $this->assert_equals('VA', $venue->state);
        $venue->reload();
        $this->assert_equals('NY', $venue->state);
    }

    public function test_reload_protected_attribute()
    {
        $book = BookAttrAccessible::find(1);

        $book->name = "Should not stay";
        $book->reload();
        $this->assert_not_equals("Should not stay", $book->name);
    }

    public function test_namespace_gets_stripped_from_table_name()
    {
        $model = new NamespaceTest\Book();
        $this->assert_equals('books', $model->table()->table);
    }

    public function test_namespace_gets_stripped_from_inferred_foreign_key()
    {
        $model = new NamespaceTest\Book();
        $table = ActiveRecord\Table::load(get_class($model));

        $this->assert_equals($table->get_relationship('parent_book')->foreign_key[0], 'book_id');
        $this->assert_equals($table->get_relationship('parent_book_2')->foreign_key[0], 'book_id');
        $this->assert_equals($table->get_relationship('parent_book_3')->foreign_key[0], 'book_id');
    }

    public function test_namespaced_relationship_associates_correctly()
    {
        $model = new NamespaceTest\Book();
        $table = ActiveRecord\Table::load(get_class($model));

        $this->assert_not_null($table->get_relationship('parent_book'));
        $this->assert_not_null($table->get_relationship('parent_book_2'));
        $this->assert_not_null($table->get_relationship('parent_book_3'));

        $this->assert_not_null($table->get_relationship('pages'));
        $this->assert_not_null($table->get_relationship('pages_2'));

        $this->assert_null($table->get_relationship('parent_book_4'));
        $this->assert_null($table->get_relationship('pages_3'));

        // Should refer to the same class
        $this->assert_same(
            ltrim($table->get_relationship('parent_book')->class_name, '\\'),
            ltrim($table->get_relationship('parent_book_2')->class_name, '\\')
        );

        // Should refer to different classes
        $this->assert_not_same(
            ltrim($table->get_relationship('parent_book_2')->class_name, '\\'),
            ltrim($table->get_relationship('parent_book_3')->class_name, '\\')
        );

        // Should refer to the same class
        $this->assert_same(
            ltrim($table->get_relationship('pages')->class_name, '\\'),
            ltrim($table->get_relationship('pages_2')->class_name, '\\')
        );
    }

    public function test_should_have_all_column_attributes_when_initializing_with_array()
    {
        $author = new Author(['name' => 'Tito']);
        $this->assert_true(count(array_keys($author->attributes())) >= 9);
    }

    public function test_defaults()
    {
        $author = new Author();
        $this->assert_equals('default_name', $author->name);
    }

    public function test_alias_attribute_getter()
    {
        $venue = Venue::find(1);
        $this->assert_equals($venue->marquee, $venue->name);
        $this->assert_equals($venue->mycity, $venue->city);
    }

    public function test_alias_attribute_setter()
    {
        $venue = Venue::find(1);
        $venue->marquee = 'new name';
        $this->assert_equals($venue->marquee, 'new name');
        $this->assert_equals($venue->marquee, $venue->name);

        $venue->name = 'another name';
        $this->assert_equals($venue->name, 'another name');
        $this->assert_equals($venue->marquee, $venue->name);
    }

    public function test_alias_from_mass_attributes()
    {
        $venue = new Venue(['marquee' => 'meme', 'id' => 123]);
        $this->assert_equals('meme', $venue->name);
        $this->assert_equals($venue->marquee, $venue->name);
    }

    public function test_gh18_isset_on_aliased_attribute()
    {
        $this->assert_true(isset(Venue::first()->marquee));
    }

    public function test_attr_accessible()
    {
        $book = new BookAttrAccessible(['name' => 'should not be set', 'author_id' => 1]);
        $this->assert_null($book->name);
        $this->assert_equals(1, $book->author_id);
        $book->name = 'test';
        $this->assert_equals('test', $book->name);
    }

    public function test_attr_protected()
    {
        $book = new BookAttrAccessible(['book_id' => 999]);
        $this->assert_null($book->book_id);
        $book->book_id = 999;
        $this->assert_equals(999, $book->book_id);
    }

    public function test_attr_protected_is_not_bypassed_by_alias()
    {
        $book = new BookAttrProtected(['name_alias' => 'sneaky']);
        $this->assert_null($book->name);
    }

    public function test_attr_protected_pk_is_not_bypassed_by_id_shortcut()
    {
        $book = new BookAttrProtected(['id' => 999]);
        $this->assert_null($book->book_id);
    }

    public function test_attr_protected_pk_is_not_bypassed_by_alias()
    {
        $book = new BookAttrProtected(['protected_pk_alias' => 999]);
        $this->assert_null($book->book_id);
    }

    public function test_attr_protected_blocks_direct_names()
    {
        $book = new BookAttrProtected(['name' => 'sneaky', 'book_id' => 999]);
        $this->assert_null($book->name);
        $this->assert_null($book->book_id);
    }

    public function test_attr_protected_mass_assignment_drop_is_logged()
    {
        $log = $this->capture_log();
        new BookAttrProtected(['name' => 'sneaky']);

        $this->assert_count(1, $log->records);
        $this->assert_equals('warning', $log->records[0]['level']);
        $this->assert_string_contains_string('BookAttrProtected', $log->records[0]['message']);
        $this->assert_string_contains_string("'name'", $log->records[0]['message']);
        $this->assert_string_contains_string('attr_protected', $log->records[0]['message']);
    }

    public function test_attr_protected_mass_assignment_drop_via_alias_logs_both_names()
    {
        $log = $this->capture_log();
        new BookAttrProtected(['name_alias' => 'sneaky']);

        $this->assert_count(1, $log->records);
        $this->assert_string_contains_string("'name'", $log->records[0]['message']);
        $this->assert_string_contains_string("'name_alias'", $log->records[0]['message']);
    }

    public function test_attr_accessible_mass_assignment_drop_is_logged()
    {
        $log = $this->capture_log();
        new BookAttrAccessible(['name' => 'sneaky']);

        $this->assert_count(1, $log->records);
        $this->assert_equals('warning', $log->records[0]['level']);
        $this->assert_string_contains_string("'name'", $log->records[0]['message']);
        $this->assert_string_contains_string('attr_accessible', $log->records[0]['message']);
    }

    public function test_allowed_mass_assignment_logs_nothing()
    {
        $log = $this->capture_log();
        new BookAttrProtected(['author_id' => 1]);

        $warnings = array_filter($log->records, fn($r) => $r['level'] === 'warning');
        $this->assert_count(0, $warnings);
    }

    public function test_guarded_mass_assignment_drop_without_logger_is_silent()
    {
        $config = ActiveRecord\Config::instance();
        $this->original_logger = $config->get_logger();

        $logger_property = new ReflectionProperty(ActiveRecord\Config::class, 'logger');
        $logger_property->setValue($config, null);

        $book = new BookAttrProtected(['name' => 'sneaky']);
        $this->assert_null($book->name);
    }

    public function test_attr_protected_leaves_other_attributes_and_aliases_assignable()
    {
        $book = new BookAttrProtected(['author_id' => 1, 'secondary_author_alias' => 2]);
        $this->assert_equals(1, $book->author_id);
        $this->assert_equals(2, $book->secondary_author_id);
    }

    public function test_attr_protected_still_allows_direct_assignment()
    {
        $book = new BookAttrProtected([]);
        $book->name = 'not mass assignment';
        $book->name_alias = 'not mass assignment either';
        $this->assert_equals('not mass assignment either', $book->name);
    }

    public function test_attr_accessible_applies_to_resolved_alias()
    {
        $book = new BookAttrAccessible(['accessible_alias' => 1]);
        $this->assert_equals(1, $book->author_id);
    }

    public function test_attr_accessible_blocks_id_shortcut_for_non_whitelisted_pk()
    {
        $book = new BookAttrAccessible(['id' => 999]);
        $this->assert_null($book->book_id);
    }

    public function test_isset()
    {
        $book = new Book();
        $this->assert_true(isset($book->name));
        $this->assert_false(isset($book->sharks));
    }

    public function test_readonly_only_halt_on_write_method()
    {
        $book = Book::first(['readonly' => true]);
        $this->assert_true($book->is_readonly());

        try {
            $book->save();
            $this->fail('expected exception ActiveRecord\ReadonlyException');
        } catch (ActiveRecord\ReadonlyException $e) {
        }

        $book->name = 'some new name';
        $this->assert_equals($book->name, 'some new name');
    }

    public function test_cast_when_using_setter()
    {
        $book = new Book();
        $book->book_id = '1';
        $this->assert_same(1, $book->book_id);
    }

    public function test_cast_when_loading()
    {
        $book = Book::find(1);
        $this->assert_same(1, $book->book_id);
        $this->assert_same('Ancient Art of Main Tanking', $book->name);
    }

    public function test_cast_defaults()
    {
        $book = new Book();
        $this->assert_same(0.0, $book->special);
    }

    public function test_transaction_committed()
    {
        $original = Author::count();
        $ret = Author::transaction(function () {
            Author::create(["name" => "blah"]);
        });
        $this->assert_equals($original + 1, Author::count());
        $this->assert_true($ret);
    }

    public function test_transaction_committed_when_returning_true()
    {
        $original = Author::count();
        $ret = Author::transaction(function () {
            Author::create(["name" => "blah"]);
            return true;
        });
        $this->assert_equals($original + 1, Author::count());
        $this->assert_true($ret);
    }

    public function test_transaction_rolledback_by_returning_false()
    {
        $original = Author::count();

        $ret = Author::transaction(function () {
            Author::create(["name" => "blah"]);
            return false;
        });

        $this->assert_equals($original, Author::count());
        $this->assert_false($ret);
    }

    public function test_transaction_rolledback_by_throwing_exception()
    {
        $original = Author::count();
        $exception = null;

        try {
            Author::transaction(function () {
                Author::create(["name" => "blah"]);
                throw new Exception("blah");
            });
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assert_not_null($exception);
        $this->assert_equals($original, Author::count());
    }

    public function test_transaction_rolledback_by_throwing_error()
    {
        // GH-43: an \Error (TypeError, DivisionByZeroError, ...) must roll
        // back like an \Exception, not leave the transaction open
        $original = Author::count();
        $error = null;

        try {
            Author::transaction(function () {
                Author::create(["name" => "blah"]);
                throw new TypeError("boom");
            });
        } catch (TypeError $e) {
            $error = $e;
        }

        $this->assert_not_null($error);
        $this->assert_false(Author::connection()->inTransaction());
        $this->assert_equals($original, Author::count());
    }

    public function test_nested_transaction_commits_with_outer()
    {
        $original = Author::count();

        $ret = Author::transaction(function () {
            Author::create(["name" => "outer"]);
            $inner = Author::transaction(function () {
                Author::create(["name" => "inner"]);
            });
            $this->assert_true($inner);
            // the inner commit must not close the real transaction (GH-104)
            $this->assert_true(Author::connection()->inTransaction());
        });

        $this->assert_true($ret);
        $this->assert_false(Author::connection()->inTransaction());
        $this->assert_equals($original + 2, Author::count());
    }

    public function test_nested_transaction_outer_rollback_discards_inner_commit()
    {
        $original = Author::count();

        $ret = Author::transaction(function () {
            Author::create(["name" => "outer"]);
            Author::transaction(function () {
                Author::create(["name" => "inner"]);
            });
            return false;
        });

        $this->assert_false($ret);
        $this->assert_equals($original, Author::count());
    }

    public function test_nested_transaction_inner_rollback_keeps_outer_writes()
    {
        $original = Author::count();

        $ret = Author::transaction(function () {
            Author::create(["name" => "outer"]);
            $inner = Author::transaction(function () {
                Author::create(["name" => "inner"]);
                return false;
            });
            $this->assert_false($inner);
        });

        $this->assert_true($ret);
        $this->assert_equals($original + 1, Author::count());
        $this->assert_equals(0, Author::count(['conditions' => ['name' => 'inner']]));
    }

    public function test_nested_transaction_error_rolls_back_only_inner_scope()
    {
        // GH-43 × GH-104: an \Error thrown at depth N must roll back to the
        // right savepoint; the outer scope keeps working and commits its own
        $original = Author::count();

        $ret = Author::transaction(function () {
            Author::create(["name" => "outer"]);
            try {
                Author::transaction(function () {
                    Author::create(["name" => "inner"]);
                    throw new TypeError("boom");
                });
            } catch (TypeError) {
            }
            $this->assert_true(Author::connection()->inTransaction());
            Author::create(["name" => "after-inner"]);
        });

        $this->assert_true($ret);
        $this->assert_equals($original + 2, Author::count());
        $this->assert_equals(0, Author::count(['conditions' => ['name' => 'inner']]));
    }

    public function test_transaction_rethrows_the_same_throwable_instance()
    {
        $thrown = new RuntimeException('original');
        $caught = null;

        try {
            Author::transaction(function () use ($thrown) {
                throw $thrown;
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assert_same($thrown, $caught);
    }

    public function test_transaction_skips_rollback_when_closure_already_ended_the_transaction()
    {
        // the inTransaction() guard: when the closure itself closed the
        // transaction (here an explicit commit; on MySQL an implicitly
        // committing DDL behaves the same), the rethrow must not attempt a
        // rollback on a connection with no active transaction
        $original = Author::count();
        $caught = null;

        try {
            Author::transaction(function () {
                Author::create(["name" => "early-commit"]);
                Author::connection()->commit();
                throw new RuntimeException("after commit");
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assert_equals("after commit", $caught->getMessage());
        $this->assert_false(Author::connection()->inTransaction());
        $this->assert_equals($original + 1, Author::count());
    }

    public function test_connection_usable_after_rolled_back_transaction()
    {
        $original = Author::count();

        try {
            Author::transaction(function () {
                Author::create(["name" => "doomed"]);
                throw new TypeError("boom");
            });
        } catch (TypeError) {
        }

        Author::create(["name" => "survivor"]);
        $this->assert_equals($original + 1, Author::count());
        $this->assert_equals(0, Author::count(['conditions' => ['name' => 'doomed']]));
    }

    public function test_nested_transaction_three_levels_deep()
    {
        $original = Author::count();

        $ret = Author::transaction(function () {
            Author::create(["name" => "level1"]);
            Author::transaction(function () {
                Author::create(["name" => "level2"]);
                Author::transaction(function () {
                    Author::create(["name" => "level3"]);
                    return false;
                });
            });
        });

        $this->assert_true($ret);
        $this->assert_equals($original + 2, Author::count());
        $this->assert_equals(0, Author::count(['conditions' => ['name' => 'level3']]));
    }

    public function test_delegate()
    {
        $event = Event::first();
        $this->assert_equals($event->venue->state, $event->state);
        $this->assert_equals($event->venue->address, $event->address);
    }

    public function test_delegate_prefix()
    {
        $event = Event::first();
        $this->assert_equals($event->host->name, $event->woot_name);
    }

    public function test_delegate_returns_null_if_relationship_does_not_exist()
    {
        $event = new Event();
        $this->assert_null($event->state);
    }

    public function test_delegate_set_attribute()
    {
        $event = Event::first();
        $event->state = 'MEXICO';
        $this->assert_equals('MEXICO', $event->venue->state);
    }

    public function test_delegate_getter_gh_98()
    {
        Venue::$use_custom_get_state_getter = true;

        $event = Event::first();
        $this->assert_equals('ny', $event->venue->state);
        $this->assert_equals('ny', $event->state);

        Venue::$use_custom_get_state_getter = false;
    }

    public function test_delegate_setter_gh_98()
    {
        Venue::$use_custom_set_state_setter = true;

        $event = Event::first();
        $event->state = 'MEXICO';
        $this->assert_equals('MEXICO#', $event->venue->state);

        Venue::$use_custom_set_state_setter = false;
    }

    public function test_table_name_with_underscores()
    {
        $this->assert_not_null(AwesomePerson::first());
    }

    public function test_model_should_default_as_new_record()
    {
        $author = new Author();
        $this->assert_true($author->is_new_record());
    }

    public function test_setter()
    {
        $author = new Author();
        $author->password = 'plaintext';
        $this->assert_equals(md5('plaintext'), $author->encrypted_password);
    }

    public function test_setter_with_same_name_as_an_attribute()
    {
        $author = new Author();
        $author->name = 'bob';
        $this->assert_equals('BOB', $author->name);
    }

    public function test_getter()
    {
        $book = Book::first();
        $this->assert_equals(strtoupper($book->name), $book->upper_name);
    }

    public function test_getter_with_same_name_as_an_attribute()
    {
        Book::$use_custom_get_name_getter = true;
        $book = new Book();
        $book->name = 'bob';
        $this->assert_equals('BOB', $book->name);
        Book::$use_custom_get_name_getter = false;
    }

    public function test_setting_invalid_date_should_set_date_to_null()
    {
        $author = new Author();
        $author->created_at = 'CURRENT_TIMESTAMP';
        $this->assertNull($author->created_at);
    }

    public function test_table_name()
    {
        $this->assert_equals('authors', Author::table_name());
    }

    public function test_undefined_instance_method()
    {
        $this->expectException(ActiveRecord\ActiveRecordException::class);
        Author::first()->find_by_name('sdf');
    }

    public function test_clear_cache_for_specific_class()
    {
        $book_table1 = ActiveRecord\Table::load('Book');
        $book_table2 = ActiveRecord\Table::load('Book');
        ActiveRecord\Table::clear_cache('Book');
        $book_table3 = ActiveRecord\Table::load('Book');

        $this->assert_true($book_table1 === $book_table2);
        $this->assert_true($book_table1 !== $book_table3);
    }

    public function test_flag_dirty()
    {
        $author = new Author();
        $author->flag_dirty('some_date');
        $this->assert_has_keys('some_date', $author->dirty_attributes());
        $this->assert_true($author->attribute_is_dirty('some_date'));
        $author->save();
        $this->assert_false($author->attribute_is_dirty('some_date'));
    }

    public function test_flag_dirty_attribute_which_does_not_exit()
    {
        $author = new Author();
        $author->flag_dirty('some_inexistant_property');
        $this->assert_null($author->dirty_attributes());
        $this->assert_false($author->attribute_is_dirty('some_inexistant_property'));
    }

    public function test_gh245_dirty_attribute_should_not_raise_php_notice_if_not_dirty()
    {
        $event = new Event(['title' => "Fun"]);
        $this->assert_false($event->attribute_is_dirty('description'));
        $this->assert_true($event->attribute_is_dirty('title'));
    }

    public function test_assigning_php_datetime_gets_converted_to_ar_datetime()
    {
        $author = new Author();
        $author->created_at = $now = new \DateTime();
        $this->assert_is_a("ActiveRecord\\DateTime", $author->created_at);
        $this->assert_datetime_equals($now, $author->created_at);
    }

    public function test_assigning_from_mass_assignment_php_datetime_gets_converted_to_ar_datetime()
    {
        $author = new Author(['created_at' => new \DateTime()]);
        $this->assert_is_a("ActiveRecord\\DateTime", $author->created_at);
    }

    public function test_get_real_attribute_name()
    {
        $venue = new Venue();
        $this->assert_equals('name', $venue->get_real_attribute_name('name'));
        $this->assert_equals('name', $venue->get_real_attribute_name('marquee'));
        $this->assert_equals(null, $venue->get_real_attribute_name('invalid_field'));
    }

    public function test_id_setter_works_with_table_without_pk_named_attribute()
    {
        $author = new Author(['id' => 123]);
        $this->assert_equals(123, $author->author_id);
    }

    public function test_query()
    {
        $row = Author::query('SELECT COUNT(*) AS n FROM authors', null)->fetch();
        $this->assert_true($row['n'] > 1);

        $row = Author::query('SELECT COUNT(*) AS n FROM authors WHERE name=?', ['Tito'])->fetch();
        $this->assert_equals(['n' => 1], $row);
    }

    public function test__isset_with_attributes()
    {
        $venue = new Venue();
        $this->assert_true($venue->__isset('name'));
        $this->assert_false($venue->__isset('attribute_does_not_exist'));
    }

    public function test__isset_with_aliased_attribute()
    {
        $venue = new Venue();
        $this->assert_true($venue->__isset('marquee'));
    }

    public function test__isset_with_getter_functions()
    {
        $venue = new Venue();
        $this->assert_true($venue->__isset('state'));
    }

    public function test__isset_with_relationships()
    {
        $venue = new Venue();
        $this->assert_true($venue->__isset('events'));
    }

    public function test_set_relationship_from_eager_load_accepts_null_model()
    {
        $book = Book::first();
        // non deve emettere deprecation e deve accettare null come primo argomento
        $book->set_relationship_from_eager_load(null, 'author');
        $this->assert_null($book->author);
    }

    public function test_serialize_unserialize_round_trip()
    {
        $book = Book::find(1);
        $serialized = serialize($book);

        // simulate waking up in a fresh process: the Table cache is cold and
        // __wakeup must re-resolve it
        ActiveRecord\Table::clear_cache();
        $copy = unserialize($serialized);

        $this->assert_true($copy instanceof Book);
        $this->assert_equals($book->attributes(), $copy->attributes());
        $this->assert_false($copy->is_dirty());
        $this->assert_false($copy->is_new_record());

        $copy->name = 'woken up';
        $copy->save();
        $this->assert_equals('woken up', Book::find(1)->name);
    }

    public function test_values_for()
    {
        $book = Book::find(1);
        $this->assert_equals(
            ['book_id' => 1, 'name' => 'Ancient Art of Main Tanking'],
            $book->values_for(['book_id', 'name'])
        );
    }

    public function test_values_for_pk()
    {
        $book = Book::find(1);
        $this->assert_equals(['book_id' => 1], $book->values_for_pk());
    }

    public function test_pk_conditions()
    {
        $this->assert_equals(['book_id' => 5], Book::pk_conditions(5));
    }

    public function test_get_column_by_inflected_name()
    {
        $table = Author::table();

        $column = $table->get_column_by_inflected_name('mixedcasefield');
        $this->assert_equals('mixedCaseField', $column->name);

        $this->assert_null($table->get_column_by_inflected_name('no_such_column'));
    }
};
