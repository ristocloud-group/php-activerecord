<?php

use ActiveRecord\ActiveRecordException;

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

    public function test_model_without_scopes_sql_unchanged(): void
    {
        $this->assert_same(Author::where('author_id', 1)->to_sql(), (new ActiveRecord\Query(Author::class))->where('author_id', 1)->to_sql());
    }
}
