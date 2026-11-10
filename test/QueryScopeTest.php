<?php

use ActiveRecord\ActiveRecordException;

/**
 * Local scopes called on a model class and on a query (spec §3.1, §4.2, §5, §9): dispatch through
 * Model::__callStatic() and Query::__call(), the group a scope's conditions are wrapped in, the
 * return contract, and the errors for a name that is no scope.
 *
 * Fixtures: authors 1 Tito (parent 3), 2 George W. Bush (parent 2), 3 Bill Clinton (parent 1),
 * 4 Uncle Bob (parent 2).
 */
class QueryScopeTest extends DatabaseTest
{
    /**
     * @param list<ActiveRecord\Model> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        return array_map(static fn($m) => (int) $m->author_id, $models);
    }

    /**
     * @return array<int, string> the name of every author, by id
     */
    private function names(): array
    {
        $names = [];
        foreach (Author::all() as $author) {
            $names[(int) $author->author_id] = $author->name;
        }

        return $names;
    }

    public function test_static_and_chained_with_arguments(): void
    {
        $this->assert_equals([1, 2, 4], $this->ids(ScopedAuthor::tito_or_parent(2)->order_by('author_id')->get()));
        $this->assert_equals([4], $this->ids(ScopedAuthor::where('author_id', '>', 1)->named('Uncle Bob')->get()));
        $this->assert_equals([1], $this->ids(ScopedAuthor::named('Tito')->get()));
    }

    public function test_where_of_a_scope_is_grouped(): void
    {
        $query = ScopedAuthor::where('author_id', '>', 1)->tito_or_parent(2)->order_by('author_id');

        $this->assert_equals([2, 4], $this->ids($query->get()));
        $this->assert_matches_regular_expression('/WHERE .*author_id.* > \? AND \(.*parent_author_id.* = \? OR .*name.* = \?\)/', $query->to_sql());
    }

    public function test_one_condition_scope_is_grouped(): void
    {
        $sql = ScopedAuthor::where('author_id', '>', 0)->named('Tito')->to_sql();

        $this->assert_matches_regular_expression('/AND \(.*name/', $sql);
    }

    public function test_scope_without_where_adds_no_group(): void
    {
        $query = ScopedAuthor::newest_first();

        $this->assert_equals([4, 3, 2, 1], $this->ids($query->get()));
        $this->assert_string_not_contains_string('WHERE', $query->to_sql());
    }

    public function test_null_return_keeps_chain(): void
    {
        $this->assert_equals([2], $this->ids(ScopedAuthor::where('author_id', 2)->noop()->get()));
    }

    public function test_other_query_returned_throws(): void
    {
        try {
            ScopedAuthor::rogue();
            $this->fail('rogue() did not throw');
        } catch (ActiveRecordException $e) {
            $this->assert_string_contains_string('rogue', $e->getMessage());
            $this->assert_string_contains_string('ActiveRecord\Query', $e->getMessage());
        }
    }

    public function test_non_query_returned_throws(): void
    {
        try {
            ScopedAuthor::untyped_bad();
            $this->fail('untyped_bad() did not throw');
        } catch (ActiveRecordException $e) {
            $this->assert_string_contains_string('untyped_bad', $e->getMessage());
            $this->assert_string_contains_string('string', $e->getMessage());
        }
    }

    public function test_nested_scope_groups(): void
    {
        $query = ScopedAuthor::where('author_id', '<', 4)->tito_named();

        $this->assert_equals([1], $this->ids($query->get()));
        $this->assert_string_contains_string('AND ((', $query->to_sql());
    }

    public function test_scope_on_joined_query(): void
    {
        $query = ScopedAuthor::join('books', 'books.author_id', '=', 'authors.author_id')->select('authors.*')->named('Tito');

        $this->assert_equals([1], array_values(array_unique($this->ids($query->get()))));
    }

    public function test_scope_inside_closure_group(): void
    {
        $this->assert_equals([1], $this->ids(ScopedAuthor::where(fn($q) => $q->named('Tito'))->get()));

        try {
            ScopedAuthor::where(fn($q) => $q->newest_first());
            $this->fail('the closure did not throw');
        } catch (ActiveRecordException $e) {
            $this->assert_string_contains_string('A where() group can only hold where clauses', $e->getMessage());
        }
    }

    public function test_inherited_scope_call(): void
    {
        $models = ScopedAuthorChild::named('Tito')->get();

        $this->assert_same(1, count($models));
        $this->assert_instance_of(ScopedAuthorChild::class, $models[0]);
    }

    public function test_unknown_chained_name_throws_bad_method_call(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Call to undefined method ActiveRecord\Query::wehre()');
        ScopedAuthor::new_query()->wehre('x');
    }

    public function test_private_query_method_from_outside(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Call to undefined method ActiveRecord\Query::compiler()');
        ScopedAuthor::new_query()->compiler();
    }

    public function test_unknown_static_name_unchanged(): void
    {
        $this->expectException(ActiveRecordException::class);
        $this->expectExceptionMessage('Call to undefined method: wehre');
        ScopedAuthor::wehre();
    }

    public function test_unknown_static_name_on_a_model_without_table(): void
    {
        try {
            ScopeGhost::typo();
            $this->fail('typo() did not throw');
        } catch (ActiveRecordException $e) {
            $this->assert_same(ActiveRecordException::class, $e::class);
            $this->assert_same('Call to undefined method: typo', $e->getMessage());
        }

        $this->assert_false(ScopeGhost::has_scope('x'));
    }

    public function test_static_scope_from_instance_method_is_undefined(): void
    {
        $this->expectException(ActiveRecordException::class);
        $this->expectExceptionMessage('Call to undefined method');
        ScopedAuthor::find(1)->call_scope_statically();
    }

    public function test_iteration_after_scope(): void
    {
        $pages = [];
        ScopedAuthor::newest_first()->chunk(2, function (array $page) use (&$pages): void {
            $pages[] = $this->ids($page);
        });
        $this->assert_equals([[4, 3], [2, 1]], $pages);

        $this->assert_same(1, count(iterator_to_array(ScopedAuthor::named('Tito')->lazy())));
        $this->assert_same(1, count(iterator_to_array(ScopedAuthor::named('Tito')->cursor())));
    }

    // R7: an earlier OR is wrapped too, so the scope filters every row, as in Eloquent
    public function test_earlier_or_is_grouped_before_a_scope(): void
    {
        $query = ScopedAuthor::where('author_id', 1)->or_where('author_id', 2)->named('Tito');

        $this->assert_equals([1], $this->ids($query->get()));
        $this->assert_matches_regular_expression('/WHERE \(.*author_id.* = \? OR .*author_id.* = \?\) AND \(.*name.* = \?\)$/', $query->to_sql());

        $query = ScopedAuthor::where('author_id', 2)->or_where('author_id', 4)->named('Tito');
        $this->assert_equals([], $this->ids($query->get()));
    }

    public function test_earlier_list_without_or_is_not_wrapped(): void
    {
        $sql = ScopedAuthor::where('author_id', '>', 0)->where('author_id', '<', 9)->named('Tito')->to_sql();

        $this->assert_matches_regular_expression('/WHERE .*author_id.* > \? AND .*author_id.* < \? AND \(.*name.* = \?\)$/', $sql);
        $this->assert_string_not_contains_string('WHERE (', $sql);
    }

    public function test_earlier_or_is_kept_as_it_is_by_a_scope_without_where(): void
    {
        $sql = ScopedAuthor::where('author_id', 1)->or_where('author_id', 2)->newest_first()->to_sql();

        $this->assert_matches_regular_expression('/WHERE .*author_id.* = \? OR .*author_id.* = \? ORDER BY/', $sql);
        $this->assert_string_not_contains_string('(', $sql);
    }

    public function test_nested_scope_after_an_earlier_or(): void
    {
        $query = ScopedAuthor::where('author_id', 1)->or_where('author_id', 2)->tito_named();

        $this->assert_equals([1], $this->ids($query->get()));
        $this->assert_matches_regular_expression('/WHERE \(.*author_id.* = \? OR .*author_id.* = \?\) AND \(\(.*name.* = \?\)\)$/', $query->to_sql());
    }

    public function test_scope_that_throws_midway_leaves_the_query_grouped(): void
    {
        $query = ScopedAuthor::where('author_id', '>', 1)->or_where('author_id', 1);

        try {
            $query->throws_midway();
            $this->fail('throws_midway() did not throw');
        } catch (RuntimeException $e) {
            $this->assert_same('the scope failed midway', $e->getMessage());
        }

        $this->assert_matches_regular_expression('/WHERE \(.*author_id.* > \? OR .*author_id.* = \?\) AND \(.*name.* = \? OR .*author_id.* = \?\)$/', $query->to_sql());
        $this->assert_same([1, 1, 'Tito', 2], $query->get_bindings());
    }

    public function test_bind_order_of_a_where_after_a_scope(): void
    {
        $query = ScopedAuthor::where('author_id', '>', 0)->or_where('author_id', 9)->tito_or_parent(2)->where('author_id', '<', 5);

        $this->assert_same([0, 9, 2, 'Tito', 5], $query->get_bindings());
        $this->assert_equals([1, 2, 4], $this->ids($query->order_by('author_id')->get()));
    }

    // R3: the flags of a where that added nothing stay on the query, so the write is refused as without a scope
    public function test_write_after_a_scope_that_adds_no_condition_is_refused(): void
    {
        $before = $this->names();
        $writes = [
            'update after where([])' => fn() => ScopedAuthor::empty_where()->update(['name' => 'Changed']),
            'delete after where([])' => fn() => ScopedAuthor::empty_where()->delete(),
            'update after a skipped when()' => fn() => ScopedAuthor::skipped_when()->update(['name' => 'Changed']),
            'delete after a skipped when()' => fn() => ScopedAuthor::new_query()->skipped_when()->delete(),
        ];

        foreach ($writes as $label => $write) {
            try {
                $write();
                $this->fail("$label was not refused");
            } catch (ActiveRecordException $e) {
                $this->assert_string_contains_string('so it would write every row: it is refused', $e->getMessage(), $label);
            }
        }

        $this->assert_same($before, $this->names());
    }

    public function test_join_with_and_limit_from_a_scope_apply(): void
    {
        $this->assert_equals([1], array_values(array_unique($this->ids(ScopedAuthor::joined_books()->where('books.name', 'Ancient Art of Main Tanking')->get()))));

        $query = ScopedAuthor::named('Tito')->with_books();
        $this->assert_same(['books'], $query->state()->includes);
        $models = $query->get();
        $this->assert_same(1, count($models));
        $this->assert_true(count($models[0]->books) > 0);

        $this->assert_equals([1, 2], $this->ids(ScopedAuthor::first_two()->get()));
    }

    // R4: inside the class a #[Scope] method is accessible, so PHP calls it itself, without the query
    public function test_attribute_scope_called_from_inside_the_class_is_the_method_itself(): void
    {
        $calls = [
            'static' => fn() => ScopedAuthor::call_attribute_scope_from_static(),
            'instance' => fn() => ScopedAuthor::find(1)->call_attribute_scope_from_instance(),
        ];

        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail("the $label call did not throw");
            } catch (TypeError $e) {
                $this->assert_same(TypeError::class, $e::class, $label);
                $this->assert_string_contains_string('ScopedAuthor::named(): Argument #1 ($q) must be of type ActiveRecord\Query, string given', $e->getMessage(), $label);
            }
        }
    }

    public function test_named_arguments_reach_a_scope(): void
    {
        $this->assert_equals([1, 2, 4], $this->ids(ScopedAuthor::tito_or_parent(parent: 2)->order_by('author_id')->get()));
        $this->assert_equals([1], $this->ids(ScopedAuthor::new_query()->named(name: 'Tito')->get()));
    }

    // R9: a query reads the scopes without loading the Table, as the model does. (ScopeGhost::new_query() loads the
    // Table itself, and throws for the missing table before any scope is read: a query built on the class alone has
    // not loaded it.)
    public function test_unknown_chained_name_on_a_model_without_table(): void
    {
        try {
            (new ActiveRecord\Query(ScopeGhost::class))->typo();
            $this->fail('typo() did not throw');
        } catch (BadMethodCallException $e) {
            $this->assert_same(BadMethodCallException::class, $e::class);
            $this->assert_same('Call to undefined method ActiveRecord\Query::typo()', $e->getMessage());
        }

        $this->assert_false((new ActiveRecord\Query(ScopeGhost::class))->has_scope('x'));
    }
}
