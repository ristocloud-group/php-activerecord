<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\DatabaseException;
use ActiveRecord\RecordNotFound;

/**
 * Global scopes applied on the builder path (spec §3.1, §3.3, §3.6, §4.2, §4.4, §6 errors 9-13): reads and bulk
 * writes are scoped, the scope groups sit between the engine's conditions and the user's WHERE, and
 * without_global_scope(s) lifts them.
 *
 * Fixtures: authors 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob. GlobalScopedAuthor hides author 2.
 */
class GlobalScopeTest extends DatabaseTest
{
    use CapturesStatements;

    /**
     * @param list<ActiveRecord\Model> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        return array_map(static fn($m) => (int) $m->author_id, $models);
    }

    /**
     * The SQL of the statements $run sends, joined by newlines.
     */
    private function sql_of(callable $run): string
    {
        return implode("\n", $this->statements($run));
    }

    public function test_builder_reads_are_scoped(): void
    {
        $this->assert_equals([1, 3, 4], $this->ids(GlobalScopedAuthor::order_by('author_id')->get()));
        $this->assert_same(3, GlobalScopedAuthor::new_query()->count());
        $this->assert_false(GlobalScopedAuthor::where('author_id', 2)->exists());
        $this->assert_null(GlobalScopedAuthor::new_query()->find(2));
        $this->assert_equals([1, 3, 4], GlobalScopedAuthor::order_by('author_id')->pluck('author_id'));
    }

    public function test_user_or_is_grouped_after_the_scope(): void
    {
        $query = GlobalScopedAuthor::where('author_id', 1)->or_where('author_id', 2);
        $this->assert_equals([1], $this->ids($query->get()));
        $this->assert_matches_regular_expression('/WHERE \(.*author_id.* != \?\) AND \(.*author_id.* = \? OR .*author_id.* = \?\)$/', $query->to_sql());
    }

    public function test_find_key_comes_first_and_bare(): void
    {
        // engine condition, then the scope group, then the user group
        $sql = $this->sql_of(fn() => GlobalScopedAuthor::where('name', 'Tito')->find(1));
        $this->assert_matches_regular_expression('/WHERE .*author_id.* = \? AND \(.*author_id.* != \?\) AND \(.*name.* = \?\)/', $sql);
    }

    public function test_removal_by_name_all_and_last_call_wins(): void
    {
        $this->assert_same(4, GlobalScopedAuthor::without_global_scope('not_bush')->count());
        $this->assert_same(4, GlobalScopedAuthorChild::without_global_scopes()->count());
        $this->assert_same(3, GlobalScopedAuthorChild::without_global_scopes(['named'])->count());
    }

    public function test_inherited_and_trait_scopes_read_the_queried_model_through_static(): void
    {
        // the parent's own scope reads static::hidden_id(), its trait's static::$hidden_name: each is the queried class
        GlobalScopeStaticBase::$seen = [];
        $this->assert_equals([1, 3], $this->ids(GlobalScopeStaticBase::order_by('author_id')->get()));
        $this->assert_equals([2, 4], $this->ids(GlobalScopeStaticChild::order_by('author_id')->get()));
        $this->assert_same(
            ['GlobalScopeStaticBase::not_hidden', 'GlobalScopeStaticBase::not_named', 'GlobalScopeStaticChild::not_hidden', 'GlobalScopeStaticChild::not_named'],
            GlobalScopeStaticBase::$seen,
        );

        // the legacy path runs the same invokers
        GlobalScopeStaticBase::$seen = [];
        $this->assert_equals([1, 3], $this->ids(GlobalScopeStaticBase::all(['order' => 'author_id'])));
        $this->assert_equals([2, 4], $this->ids(GlobalScopeStaticChild::all(['order' => 'author_id'])));
        $this->assert_same(2, GlobalScopeStaticChild::count());
        $this->assert_same(
            ['GlobalScopeStaticBase::not_hidden', 'GlobalScopeStaticBase::not_named', 'GlobalScopeStaticChild::not_hidden', 'GlobalScopeStaticChild::not_named',
                'GlobalScopeStaticChild::not_hidden', 'GlobalScopeStaticChild::not_named'],
            GlobalScopeStaticBase::$seen,
        );
    }

    public function test_builder_writes_are_scoped(): void
    {
        $this->assert_same(3, GlobalScopedAuthor::new_query()->update(['name' => 'x']));
        $this->assert_same('George W. Bush', Author::find(2)->name);
    }

    public function test_builder_delete_is_scoped(): void
    {
        $this->assert_same(1, GlobalScopedAuthor::where('author_id', '<=', 2)->delete());
        $this->assert_equals([2, 3, 4], $this->ids(Author::new_query()->order_by('author_id')->get()));
    }

    public function test_errors(): void
    {
        $this->assert_exception_message_contains('has no global scope named nope', fn() => GlobalScopedAuthor::without_global_scope('nope'), ActiveRecordException::class);
        $this->assert_exception_message_contains("cannot be used on a where() group", fn() => GlobalScopedAuthor::where(fn($q) => $q->without_global_scope('not_bush'))->get(), ActiveRecordException::class);
        $this->assert_exception_message_contains('can only add where clauses: it also adds order_by', fn() => GlobalScopeOrderBy::new_query()->get(), ActiveRecordException::class);
        $this->assert_exception_message_contains('must return null or the query it was given', fn() => GlobalScopeReturnsOther::new_query()->get(), ActiveRecordException::class);
        $this->assert_exception_message_contains('is applied again inside its own body', fn() => GlobalScopeReentrant::new_query()->get(), ActiveRecordException::class);
        $this->assert_exception_message_contains('is applied again inside its own body', fn() => GlobalScopeCycleA::new_query()->get(), ActiveRecordException::class);
    }

    public function test_error_messages_in_full(): void
    {
        $this->assert_exception_message_contains('GlobalScopedAuthor has no global scope named nope: its global scopes are not_bush', fn() => GlobalScopedAuthor::without_global_scopes(['nope']), ActiveRecordException::class);
        $this->assert_exception_message_contains('GlobalScopedAuthorChild has no global scope named nope: its global scopes are not_bush, named', fn() => GlobalScopedAuthorChild::without_global_scope('nope'), ActiveRecordException::class);
        $this->assert_exception_message_contains('Author has no global scope named nope: it has none', fn() => Author::without_global_scope('nope'), ActiveRecordException::class);
        $this->assert_exception_message_contains("without_global_scopes() cannot be used on a where() group or on a global scope's query: they carry no scopes", fn() => GlobalScopedAuthor::where(fn($q) => $q->without_global_scopes())->get(), ActiveRecordException::class);
        $this->assert_exception_message_contains('The global scope ordered() of GlobalScopeOrderBy can only add where clauses: it also adds order_by', fn() => GlobalScopeOrderBy::new_query()->to_sql(), ActiveRecordException::class);
        $this->assert_exception_message_contains('The scope other() of GlobalScopeReturnsOther must return null or the query it was given, ActiveRecord\Query returned', fn() => GlobalScopeReturnsOther::new_query()->count(), ActiveRecordException::class);
        $this->assert_exception_message_contains("The global scope again() of GlobalScopeReentrant is applied again inside its own body: a query of GlobalScopeReentrant run there must remove it with without_global_scope('again')", fn() => GlobalScopeReentrant::new_query()->get(), ActiveRecordException::class);
        $this->assert_exception_message_contains("The global scope to_b() of GlobalScopeCycleA is applied again inside its own body: a query of GlobalScopeCycleA run there must remove it with without_global_scope('to_b')", fn() => GlobalScopeCycleA::new_query()->get(), ActiveRecordException::class);
    }

    public function test_a_global_scope_query_carries_no_scopes(): void
    {
        $model = new class extends GlobalScopedAuthor {
            #[ActiveRecord\GlobalScope]
            protected static function removes(ActiveRecord\Query $q): ?ActiveRecord\Query
            {
                return $q->without_global_scope('not_bush');
            }
        };

        $this->assert_exception_message_contains("without_global_scope() cannot be used on a where() group or on a global scope's query", fn() => $model::new_query()->get(), ActiveRecordException::class);
    }

    public function test_a_refused_removal_leaves_the_query_as_it_was(): void
    {
        $query = GlobalScopedAuthorChild::new_query();
        $this->assert_exception_message_contains('has no global scope named nope', fn() => $query->without_global_scopes(['named', 'nope']), ActiveRecordException::class);
        $this->assert_same(['all' => false, 'removed' => [], 'trashed' => 'default'], $query->state()->scopes);
        $this->assert_same(['all' => false, 'removed' => ['named'], 'trashed' => 'default'], $query->without_global_scope('named')->without_global_scopes(['named'])->state()->scopes);
    }

    public function test_the_scope_runs_again_after_an_error_in_its_body(): void
    {
        // the marks of a scope that threw are cleared: the next compile is not taken for a re-entry
        $this->assert_exception_message_contains('is applied again inside its own body', fn() => GlobalScopeReentrant::new_query()->get(), ActiveRecordException::class);
        $this->assert_same(4, GlobalScopeReentrant::without_global_scope('again')->count());
        $this->assert_exception_message_contains('is applied again inside its own body', fn() => GlobalScopeReentrant::new_query()->count(), ActiveRecordException::class);
    }

    public function test_a_scope_running_in_a_fiber_is_marked_for_that_fiber_only(): void
    {
        $fiber = new Fiber(fn() => GlobalScopeSuspends::new_query()->count());
        $fiber->start(); // paused inside the scope's body

        // the main flow applies the same scope: it is not running there
        $this->assert_same(3, GlobalScopeSuspends::new_query()->count());

        $fiber->resume();
        $this->assert_true($fiber->isTerminated());
        $this->assert_same(3, $fiber->getReturn());
    }

    public function test_a_scope_applied_again_inside_its_body_in_a_fiber_throws_there(): void
    {
        // error 11 from the fiber branch of the guard: thrown inside the fiber, out of start()
        $fiber = new Fiber(fn() => GlobalScopeReentrant::new_query()->get());
        $this->assert_exception_message_contains(
            "The global scope again() of GlobalScopeReentrant is applied again inside its own body",
            fn() => $fiber->start(),
            ActiveRecordException::class,
        );
        $this->assert_true($fiber->isTerminated());

        // the main flow carries no mark of it
        $this->assert_same(4, GlobalScopeReentrant::without_global_scope('again')->count());
        $this->assert_exception_message_contains('is applied again inside its own body', fn() => GlobalScopeReentrant::new_query()->count(), ActiveRecordException::class);
    }

    public function test_a_scope_that_throws_in_a_fiber_is_no_longer_marked_there(): void
    {
        $fiber = new Fiber(function (): int {
            GlobalScopeThrowsOnDemand::$fail = true;

            try {
                GlobalScopeThrowsOnDemand::new_query()->get();
                $this->fail('the scope did not throw');
            } catch (RuntimeException $e) {
                $this->assert_same('the scope failed', $e->getMessage());
            } finally {
                GlobalScopeThrowsOnDemand::$fail = false;
            }

            // in the same fiber, the scope runs again: a mark left by the throw would be taken for a re-entry
            return GlobalScopeThrowsOnDemand::new_query()->count();
        });

        $fiber->start();
        $this->assert_true($fiber->isTerminated());
        $this->assert_same(3, $fiber->getReturn());
        $this->assert_same(3, GlobalScopeThrowsOnDemand::new_query()->count());
    }

    public function test_inner_query_that_removes_the_scope_is_allowed(): void
    {
        $this->assert_same(4, GlobalScopeInnerRemoval::new_query()->count());
    }

    public function test_when_false_in_a_scope_adds_no_group_and_does_not_block_writes(): void
    {
        $this->assert_false(str_contains(GlobalScopeSkippedWhen::new_query()->to_sql(), 'WHERE'));
        $this->assert_same(4, GlobalScopeSkippedWhen::new_query()->update(['name' => 'x']));
        $this->assert_same('x', Author::find(2)->name);
    }

    public function test_aggregate_subquery_has_the_scope_once(): void
    {
        $count = null;
        $sql = $this->sql_of(function () use (&$count) {
            $count = GlobalScopedAuthor::new_query()->limit(2)->count();
        });

        $this->assert_same(2, $count);
        $this->assert_string_contains_string(') AS t', $sql);
        $this->assert_same(1, substr_count($sql, '!='));

        $sql = $this->sql_of(fn() => GlobalScopedAuthor::new_query()->limit(2)->exists());
        $this->assert_string_contains_string(') AS t', $sql);
        $this->assert_same(1, substr_count($sql, '!='));
    }

    public function test_scope_columns_are_qualified_with_builder_joins(): void
    {
        $join = GlobalScopedAuthor::join('books', 'books.author_id', '=', 'authors.author_id');
        $this->assert_matches_regular_expression('/WHERE \(\W?authors\W?\.\W?author_id\W? != \?\)$/', $join->to_sql());
        $this->assert_same(1, $join->count()); // book 1 is Tito's, book 2 George W. Bush's

        $aliased = GlobalScopedAuthor::from('authors', 'a')->join('books', 'books.author_id', '=', 'a.author_id');
        $this->assert_matches_regular_expression('/WHERE \(\W?a\W?\.\W?author_id\W? != \?\)$/', $aliased->to_sql());

        $raw = GlobalScopedAuthor::from_raw('authors')->join('books', 'books.author_id', '=', 'authors.author_id');
        $this->assert_matches_regular_expression('/WHERE \(\W?author_id\W? != \?\)$/', $raw->to_sql());
    }

    public function test_keyset_pages_put_the_bound_with_the_user_wheres(): void
    {
        $pages = [];
        $sql = $this->sql_of(function () use (&$pages) {
            GlobalScopedAuthor::where('author_id', '<', 4)->chunk_by_id(1, function (array $models) use (&$pages) {
                $pages[] = $this->ids($models);
            });
        });

        $this->assert_equals([[1], [3]], $pages);
        // the probe of the highest key is scoped too
        $this->assert_matches_regular_expression('/SELECT MAX\(\W?author_id\W?\) FROM \W?authors\W? WHERE \(\W?author_id\W? != \?\) AND \(\W?author_id\W? < \?\)/', $sql);
        $this->assert_matches_regular_expression('/WHERE \(\W?author_id\W? != \?\) AND \(\W?author_id\W? < \? AND \W?author_id\W? <= \? AND \W?author_id\W? > \?\) ORDER BY/', $sql);
    }

    public function test_legacy_finders_are_scoped(): void
    {
        $this->assert_null(GlobalScopedAuthor::find('first', ['conditions' => ['author_id = ?', 2]]));
        $this->assert_same(3, GlobalScopedAuthor::count());
        $this->assert_false(GlobalScopedAuthor::exists(2));
        $this->assert_null(GlobalScopedAuthor::find_by_name('George W. Bush'));
        $this->assert_same(3, count(GlobalScopedAuthor::all()));
        $this->assert_same(4, GlobalScopedAuthor::last()->author_id);
        $this->assert_same(1, count(GlobalScopedAuthor::all(['conditions' => 'author_id = 1 OR author_id = 2'])));
    }

    public function test_find_many_with_a_hidden_id_throws(): void
    {
        $this->expect_exception(RecordNotFound::class);
        GlobalScopedAuthor::find([1, 2]);
    }

    public function test_legacy_bulk_writes_are_scoped(): void
    {
        $this->assert_same(3, GlobalScopedAuthor::update_all(['set' => ['name' => 'x']]));
        $this->assert_same(3, GlobalScopedAuthor::delete_all());
        $this->assert_same(1, Author::count());
    }

    public function test_legacy_joins_qualify_the_scope_and_from_does_not(): void
    {
        // authors joined with books: author_id is a column of both tables (book 1 is Tito's, book 2 George W. Bush's)
        $found = null;
        $sql = $this->sql_of(function () use (&$found) {
            $found = GlobalScopedAuthor::find('all', ['joins' => 'INNER JOIN books ON books.author_id = authors.author_id']);
        });
        $this->assert_sql_has('WHERE (authors.author_id != ?)', $sql);
        $this->assert_equals([1], $this->ids($found));

        // 'from' without joins: unqualified, and working
        $sql = $this->sql_of(function () use (&$found) {
            $found = GlobalScopedAuthor::find('all', ['from' => 'authors']);
        });
        $this->assert_matches_regular_expression('/WHERE \(\W?author_id\W? != \?\)$/', $sql);
        $this->assert_same(3, count($found));

        $sql = $this->sql_of(function () use (&$found) {
            $found = GlobalScopedAuthor::find('first', ['from' => 'authors']);
        });
        $this->assert_matches_regular_expression('/WHERE \(\W?author_id\W? != \?\)/', $sql);
        $this->assert_same(1, $found->author_id);

        // last() reverses the order by the primary key: the scope is ANDed, the reversed order kept
        $sql = $this->sql_of(function () use (&$found) {
            $found = GlobalScopedAuthor::last(['from' => 'authors']);
        });
        $this->assert_matches_regular_expression('/WHERE \(\W?author_id\W? != \?\) ORDER BY .*author_id.* DESC/i', $sql);
        $this->assert_same(4, $found->author_id);
    }

    /**
     * The statements $run sends until it throws, and the exception it throws (null if none).
     *
     * @return array{list<string>, \Throwable|null}
     */
    private function statements_until_failure(callable $run): array
    {
        $logged = [];
        $thrown = null;

        try {
            $this->statements($run, function (string $statement) use (&$logged): void {
                $logged[] = $statement;
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        return [$logged, $thrown];
    }

    public function test_legacy_from_with_joins_leaves_the_scope_unqualified_and_the_database_finds_it_ambiguous(): void
    {
        // spec §3.6 Limit: 'from' with joins names no rows, so the scope's author_id stays as written, and books has one
        // too. The library throws nothing ahead of time: the statement is sent, and the database refuses it
        [$logged, $thrown] = $this->statements_until_failure(fn() => GlobalScopedAuthor::find('all', [
            'from' => 'authors',
            'joins' => 'INNER JOIN books ON books.author_id = authors.author_id',
        ]));

        $this->assert_instance_of(DatabaseException::class, $thrown);
        $this->assert_string_contains_string('ambiguous', strtolower($thrown->getMessage()));
        $this->assert_matches_regular_expression('/INNER JOIN books ON books\.author_id = authors\.author_id WHERE \(\W?author_id\W? != \?\)$/', end($logged));
    }

    public function test_builder_from_raw_with_a_join_leaves_the_scope_unqualified_and_the_database_finds_it_ambiguous(): void
    {
        [$logged, $thrown] = $this->statements_until_failure(fn() => GlobalScopedAuthor::new_query()
            ->from_raw('authors')
            ->join('books', 'books.author_id', '=', 'authors.author_id')
            ->get());

        $this->assert_instance_of(DatabaseException::class, $thrown);
        $this->assert_string_contains_string('ambiguous', strtolower($thrown->getMessage()));
        $this->assert_matches_regular_expression('/WHERE \(\W?author_id\W? != \?\)$/', end($logged));
    }

    public function test_line_comment_in_user_where_is_closed(): void
    {
        $this->assert_same(1, count(GlobalScopedAuthor::all(['conditions' => "author_id = 1 -- note"])));
    }

    public function test_scope_on_an_alias_attribute(): void
    {
        $this->assert_same(3, GlobalScopedAliasAuthor::count());
        $this->assert_same(3, GlobalScopedAliasAuthor::new_query()->count());
    }

    public function test_legacy_scope_groups_come_first_and_the_user_where_is_parenthesized(): void
    {
        $sql = $this->sql_of(fn() => GlobalScopedAuthorChild::all(['conditions' => ['author_id IN(?) OR name = ?', [1, 2, 3], 'x']]));
        $this->assert_matches_regular_expression('/WHERE \(\W?author_id\W? != \?\) AND \(\W?name\W? IS NOT NULL\) AND \(author_id IN\(\?,\?,\?\) OR name = \?\)$/', $sql);
        $this->assert_equals([1, 3], $this->ids(GlobalScopedAuthor::all(['conditions' => ['author_id IN(?)', [1, 2, 3]], 'order' => 'author_id'])));
        $this->assert_equals([1], $this->ids(GlobalScopedAuthor::all(['conditions' => ['name' => ['Tito', 'George W. Bush']]])));
    }

    public function test_legacy_falsy_user_where_leaves_the_scopes_only(): void
    {
        $sql = $this->sql_of(fn() => GlobalScopedAuthor::all(['conditions' => '0']));
        $this->assert_matches_regular_expression('/WHERE \(\W?author_id\W? != \?\)$/', $sql);
        $this->assert_same(3, count(GlobalScopedAuthor::all(['conditions' => '0'])));
    }

    public function test_legacy_find_by_pk_folds_the_key_into_the_user_where(): void
    {
        $this->assert_same('Tito', GlobalScopedAuthor::find(1, ['conditions' => ['name' => 'Tito']])->name);
        $this->assert_exception_message_contains("Couldn't find GlobalScopedAuthor with ID=2", fn() => GlobalScopedAuthor::find(2), RecordNotFound::class);

        $sql = $this->sql_of(fn() => GlobalScopedAuthor::find(1, ['conditions' => ['name' => 'Tito']]));
        $this->assert_matches_regular_expression('/WHERE \(\W?author_id\W? != \?\) AND \(\W?author_id\W? ?= ?\? AND \(\W?name\W? ?= ?\?\)\)/', $sql);
    }

    public function test_table_find_and_exists_are_scoped_and_options_to_sql_is_not(): void
    {
        $table = GlobalScopedAuthor::table();
        $this->assert_same(3, count($table->find(['order' => 'author_id'])));
        $this->assert_false($table->exists(['conditions' => ['author_id' => 2]]));
        $this->assert_false(str_contains($table->options_to_sql(['conditions' => ['author_id' => 2]])->to_s(), '!='));
    }

    public function test_legacy_writes_with_conditions_limit_and_order(): void
    {
        $this->assert_same(1, GlobalScopedAuthor::update_all(['set' => ['name' => 'x'], 'conditions' => ['author_id <= ?', 2]]));
        $this->assert_same('George W. Bush', Author::find(2)->name);
        $this->assert_same(1, GlobalScopedAuthor::delete_all(['conditions' => 'author_id > 1', 'order' => 'author_id', 'limit' => 1]));
        $this->assert_equals([1, 2, 4], $this->ids(Author::all(['order' => 'author_id'])));
    }

    public function test_model_without_scopes_sql_unchanged(): void
    {
        $this->assert_same(Author::where('author_id', 1)->to_sql(), (new ActiveRecord\Query(Author::class))->where('author_id', 1)->to_sql());
    }

    public function test_reload_drops_every_scope(): void
    {
        $scoped = GlobalScopedAuthor::without_global_scopes()->find(2);
        $this->assert_same('George W. Bush', $scoped->reload()->name);
    }

    public function test_reload_of_a_row_that_is_gone_keeps_the_record_not_found_message(): void
    {
        $scoped = GlobalScopedAuthor::without_global_scopes()->find(2);
        GlobalScopedAuthor::connection()->query('DELETE FROM authors WHERE author_id = 2');
        $this->assert_exception_message_contains(
            "Couldn't find GlobalScopedAuthor with ID=2",
            fn() => $scoped->reload(),
            RecordNotFound::class,
        );
    }

    public function test_reload_composite_key_drops_every_scope(): void
    {
        $this->assert_null(GlobalScopedCompositeItem::new_query()->where('user_id', 3)->first());
        $hidden = GlobalScopedCompositeItem::without_global_scopes()->where('user_id', 3)->first();
        $this->assert_same(3, (int) $hidden->reload()->user_id);
        $this->assert_same(1, (int) $hidden->story_id);
    }

    public function test_reload_without_a_primary_key_throws_and_leaves_the_object_as_it_was(): void
    {
        // pkless_items has no primary key: there is no row to name, rather than the first row of the table
        GlobalScopedPklessItem::create(['code' => 2, 'name' => 'second']);
        $item = GlobalScopedPklessItem::new_query()->where('code', 2)->first();

        $this->assert_exception_message_contains(
            'Cannot reload, no primary key defined for: GlobalScopedPklessItem',
            fn() => $item->reload(),
            ActiveRecordException::class,
        );
        $this->assert_same(['code' => 2, 'name' => 'second'], $item->attributes());
    }

    public function test_uniqueness_counts_rows_hidden_by_scopes(): void
    {
        $author = new GlobalScopedUniqueAuthor(['name' => 'George W. Bush']);
        $this->assert_false($author->is_valid());
        $this->assert_true((new GlobalScopedUniqueAuthor(['name' => 'Nobody Yet']))->is_valid());
    }

    public function test_reload_and_uniqueness_of_unscoped_models_still_use_the_public_methods(): void
    {
        $author = FindRecordingAuthor::find(1);
        FindRecordingAuthor::$finds = 0;
        FindRecordingAuthor::$exists_calls = 0;

        $author->reload();
        $this->assert_same(1, FindRecordingAuthor::$finds);

        (new FindRecordingAuthor(['name' => 'Tito']))->is_valid();
        $this->assert_same(1, FindRecordingAuthor::$exists_calls);
    }
}
