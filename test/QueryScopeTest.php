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
     * @param list<Model> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        return array_map(static fn($m) => (int) $m->author_id, $models);
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
}
