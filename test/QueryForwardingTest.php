<?php

use ActiveRecord\Model;
use ActiveRecord\Query;

/**
 * Model::new_query() and the static forwarding of Model::__callStatic() (spec §4.1): a builder method
 * called on a model class is that call on a new query of the class. __callStatic() tries the dynamic
 * finders first, then refuses a static update() and delete() (they would write every row), then
 * forwards the names of Query::FORWARDABLE, and answers anything else with its legacy "Call to
 * undefined method" error.
 */
class QueryForwardingTest extends DatabaseTest
{
    /**
     * What the recording query of test_forwarding_goes_through_new_query_with_the_arguments_untouched
     * was called with
     *
     * @var array<int|string, mixed>|null
     */
    public static ?array $received = null;

    public function test_new_query_and_forwarding(): void
    {
        $this->assert_instance_of(ActiveRecord\Query::class, Author::new_query());
        $this->assert_same(4, count(Author::get())); // forwarded to new_query()->get()
        $this->assert_same('Tito', Author::find_by_name('Tito')->name); // dynamic finders keep precedence
    }

    // Only a dynamic finder takes a trailing options hash out of its arguments (the forwarded calls keep
    // theirs, see below), and the four families are told by their names: the authors with parent 2 are
    // Uncle Bob (4) and George W. Bush (2).
    public function test_each_dynamic_finder_family_still_takes_its_options_hash(): void
    {
        $this->assert_same('Uncle Bob', Author::find_by_parent_author_id(2, ['order' => 'author_id desc'])->name);
        $this->assert_same(['Uncle Bob'], array_map(fn($author) => $author->name, Author::find_all_by_parent_author_id(2, ['order' => 'author_id desc', 'limit' => 1])));
        $this->assert_same('Uncle Bob', Author::find_or_create_by_parent_author_id(2, ['order' => 'author_id desc'])->name);
        $this->assert_equals(2, Author::count_by_parent_author_id(2));
        $this->assert_equals(0, Author::count_by_parent_author_id(2, ['limit' => 0]));
    }

    public function test_new_query_is_a_query_on_the_class_it_is_called_on(): void
    {
        $authors = ShadowingAuthor::new_query()->get();

        $this->assert_same(4, count($authors));
        $this->assert_instance_of(ShadowingAuthor::class, $authors[0]);
        $this->assert_instance_of(ShadowingAuthor::class, ShadowingAuthor::get()[0]);
    }

    // A consumer's base class overrides the legacy finders (the monolith base model adds its soft-delete condition to all());
    // the builder entry does not run them (spec §6.3), however it is reached.
    public function test_new_query_does_not_run_the_legacy_finders(): void
    {
        $this->assert_same(1, count(OverridingAuthor::all()));
        $this->assert_same(4, count(OverridingAuthor::new_query()->get()));
        $this->assert_same(4, count(OverridingAuthor::get()));
    }

    // "Order::method(...) is Order::new_query()->method(...)" (spec §4.1): a model that overrides new_query()
    // is where the forwarded calls land, and the arguments reach it as the caller gave them. A finder takes
    // a trailing options hash out of its arguments, and adds a positional null after named ones, which a
    // forwarded call must not suffer: a hash that looks like finder options is the caller's, as is a named
    // argument.
    public function test_forwarding_goes_through_new_query_with_the_arguments_untouched(): void
    {
        $model = new class extends Author {
            // the class is what is used here: an instance of it is no record of a table
            public function __construct() {}

            public static function new_query(): Query
            {
                return new class (static::class) extends Query {
                    public function get(mixed ...$arguments): array
                    {
                        QueryForwardingTest::$received = $arguments;

                        return [];
                    }
                };
            }
        };
        self::$received = null;

        $this->assert_same([], $model::get(['limit' => 1], 'x', named: 'y'));
        $this->assert_same([['limit' => 1], 'x', 'named' => 'y'], self::$received);

        self::$received = null;
        $this->assert_same([], $model::get(['conditions' => ['name' => 'Tito']]));
        $this->assert_same([['conditions' => ['name' => 'Tito']]], self::$received);
    }

    // A query is mutable (spec §3.2), so each new_query() is a query of its own: a builder method changes the
    // query it is called on and no other, nor any later new_query(), nor the query of a forwarded call.
    public function test_every_new_query_is_a_new_query_and_changing_one_leaves_the_others_alone(): void
    {
        $every_author = 'SELECT * FROM ' . $this->conn->quote_name('authors');
        $their_names = 'SELECT ' . $this->conn->quote_name('name') . ' FROM ' . $this->conn->quote_name('authors');

        $first = Author::new_query();
        $second = Author::new_query();
        $this->assert_not_same($first, $second);
        $this->assert_not_same($first->state(), $second->state());

        $this->assert_same($first, $first->select('name'));
        $this->assert_same($their_names, $first->to_sql());
        $this->assert_same($every_author, $second->to_sql());
        $this->assert_same($every_author, Author::new_query()->to_sql());

        // a forwarded call is a call on a new query too
        $this->assert_same('SELECT ' . $this->conn->quote_name('author_id') . ' FROM ' . $this->conn->quote_name('authors'), Author::select('author_id')->to_sql());
        $this->assert_same($every_author, Author::to_sql());
        $this->assert_same($their_names, $first->to_sql());
    }

    public function test_static_update_and_delete_are_refused(): void
    {
        $this->assert_exception_message_contains('would write every row', fn() => Author::update(['name' => 'x']), ActiveRecord\ActiveRecordException::class);
    }

    // The refusal names the class the call was made on and the calls that write what was meant, and it is
    // thrown before any statement is sent. PHP refuses a static call of the instance method delete() itself
    // ("Non-static method ... cannot be called statically") before __callStatic() sees it, so that one is
    // reached through the hook directly.
    public function test_the_refusal_sends_no_statement_and_says_what_to_call(): void
    {
        $this->assert_same(
            [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => 'ShadowingAuthor::update() would write every row: use ShadowingAuthor::where(...)->update(...) or ShadowingAuthor::new_query()->update(...)']],
            GoldenStatements::capture(fn() => ShadowingAuthor::update(['name' => 'x']))
        );
        $this->assert_same(
            [['exception' => ActiveRecord\ActiveRecordException::class, 'message' => 'Author::delete() would write every row: use Author::where(...)->delete(...) or Author::new_query()->delete(...)']],
            GoldenStatements::capture(fn() => Author::__callStatic('delete', []))
        );
    }

    public function test_unknown_static_keeps_the_legacy_message(): void
    {
        $this->assert_exception_message_contains('Call to undefined method: frobnicate', fn() => Author::frobnicate(), ActiveRecord\ActiveRecordException::class);
    }

    // latest() is a builder method (Query::latest()), so Author::latest() is forwarded to a new query; a
    // class that defines a static latest() of its own is called by PHP itself, and __callStatic() never sees it
    public function test_a_subclass_method_shadows_a_forwarded_one(): void
    {
        $this->assert_true(in_array('latest', Query::FORWARDABLE, true));
        $this->assert_true(method_exists(ShadowingAuthor::class, 'latest'));
        $this->assert_false(method_exists(Author::class, 'latest'));

        $this->assert_instance_of(Query::class, Author::latest());
        $this->assert_sql_has('ORDER BY created_at DESC', Author::latest()->to_sql());
        $this->assert_same('own', ShadowingAuthor::latest());
        // the builder method is still there, through the entry that no class defines
        $this->assert_sql_has('ORDER BY created_at DESC', ShadowingAuthor::new_query()->latest()->to_sql());
    }

    public function test_forwardable_matches_the_method_tags(): void
    {
        preg_match_all('/@method static \S+ (\w+)\(/', (string) (new ReflectionClass(ActiveRecord\Model::class))->getDocComment(), $m);

        $tagged = array_values(array_unique($m[1]));
        $forwardable = array_values(array_unique(ActiveRecord\Query::FORWARDABLE));
        sort($tagged);
        sort($forwardable);

        $this->assert_equals($forwardable, $tagged);
    }

    // What the list may hold (spec §4.1): public builder methods of Query, once each. Not update() and
    // delete(), which are refused; not a name that Model defines, static or not, which PHP calls itself
    // and so keeps its legacy meaning (find, first, count, update_all, upsert, query, readonly, ...); not
    // the engine's internals (from_options, to_sql_builder, state, execute_*).
    public function test_forwardable_lists_only_public_builder_methods_that_model_does_not_define(): void
    {
        $names = Query::FORWARDABLE;
        $this->assert_same(array_values(array_unique($names)), $names, 'a name is listed once');

        foreach ($names as $name) {
            $this->assert_true(method_exists(Query::class, $name), "Query::$name() exists");

            $method = new ReflectionMethod(Query::class, $name);
            $this->assert_true($method->isPublic() && !$method->isStatic(), "Query::$name() is a public instance method");
            $this->assert_false(str_contains((string) $method->getDocComment(), '@internal'), "Query::$name() is not internal");
            $this->assert_false(method_exists(Model::class, $name), "Model::$name() is a method of its own, which PHP calls before __callStatic()");
            $this->assert_false(in_array($name, ['update', 'delete'], true), "$name() is refused when called statically");
        }
    }
}
