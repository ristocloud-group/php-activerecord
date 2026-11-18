<?php

use ActiveRecord\Query;

/**
 * Guards for spec §9.3 items 2 and 7: every scoped entry carries the global scope of an opted-in model, and every
 * documented unscoped path (spec §3.6, §4.3) does not. The scoped terminal methods of Query are derived by reflection
 * from Query::FORWARDABLE, so a method added without a sample call here fails the test.
 *
 * Fixtures: authors 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob. GlobalScopedAuthor hides author 2.
 */
class GlobalScopeGuardTest extends DatabaseTest
{
    use CapturesStatements;

    /**
     * Methods of Query::FORWARDABLE that build or inspect a query without sending a statement of rows.
     */
    private const array NOT_TERMINAL = ['without_global_scope', 'without_global_scopes', 'clone', 'tap', 'when', 'unless'];

    /**
     * The statements $run sends, with the identifier quotes of every adapter stripped.
     *
     * @return list<string>
     */
    private function plain(callable $run): array
    {
        return array_map(static fn(string $sql): string => str_replace(['`', '"', '[', ']'], '', $sql), $this->statements($run));
    }

    private function has_predicate(string $sql): bool
    {
        return 1 === preg_match('/author_id\s*!=\s*\?/', $sql);
    }

    /**
     * @return list<string> the SELECT, UPDATE and DELETE statements on the authors table among the statements $run
     *                      sends (not the schema introspection of a first use, which PostgreSQL logs as a SELECT)
     */
    private function data_statements(callable $run): array
    {
        return array_values(array_filter(
            $this->plain($run),
            static fn(string $sql): bool => 1 === preg_match('/^\s*(SELECT|UPDATE|DELETE)\b/i', $sql) && 1 === preg_match('/\b(FROM|UPDATE)\s+authors\b/i', $sql),
        ));
    }

    /**
     * @param list<string> $statements
     */
    private function assert_all_scoped(array $statements, string $what): void
    {
        $this->assert_true([] !== $statements, "$what sent no statement");

        foreach ($statements as $sql) {
            $this->assert_true($this->has_predicate($sql), "$what: no scope predicate in: $sql");
        }
    }

    /**
     * One sample call per terminal Query method of Query::FORWARDABLE (plus find, first, exists, count and the writes).
     *
     * @return array<string, callable(): mixed>
     */
    private function samples(): array
    {
        $q = static fn(): Query => GlobalScopedAuthor::new_query();

        return [
            'get' => fn() => $q()->get(),
            'first' => fn() => $q()->first(),
            'first_or_fail' => fn() => $q()->first_or_fail(),
            'first_or' => fn() => $q()->first_or(fn() => null),
            'sole' => fn() => $q()->where('author_id', 1)->sole(),
            'find' => fn() => $q()->find(1),
            'find_or_fail' => fn() => $q()->find_or_fail(1),
            'find_many' => fn() => $q()->find_many([1, 3]),
            'find_or' => fn() => $q()->find_or(1, fn() => null),
            'value' => fn() => $q()->value('name'),
            'sole_value' => fn() => $q()->where('author_id', 1)->sole_value('name'),
            'pluck' => fn() => $q()->pluck('name'),
            'exists' => fn() => $q()->exists(),
            'doesnt_exist' => fn() => $q()->doesnt_exist(),
            'count' => fn() => $q()->count(),
            'min' => fn() => $q()->min('author_id'),
            'max' => fn() => $q()->max('author_id'),
            'sum' => fn() => $q()->sum('author_id'),
            'avg' => fn() => $q()->avg('author_id'),
            'average' => fn() => $q()->average('author_id'),
            'update' => fn() => $q()->update(['name' => 'x']),
            'delete' => fn() => $q()->delete(),
            'chunk' => fn() => $q()->chunk(2, fn() => null),
            'lazy' => fn() => iterator_to_array($q()->lazy(2), false),
            'cursor' => fn() => iterator_to_array($q()->cursor(), false),
            'cursor_stream' => fn() => iterator_to_array($q()->cursor(true), false),
            'chunk_by_id' => fn() => $q()->chunk_by_id(2, fn() => null),
            'chunk_by_id_desc' => fn() => $q()->chunk_by_id_desc(2, fn() => null),
            'lazy_by_id' => fn() => iterator_to_array($q()->lazy_by_id(2), false),
            'lazy_by_id_desc' => fn() => iterator_to_array($q()->lazy_by_id_desc(2), false),
        ];
    }

    /**
     * The forwardable methods that send no statement: they return a string or an array that is not rows.
     *
     * @return list<string>
     */
    private function sql_text_methods(): array
    {
        return ['to_sql', 'to_raw_sql', 'get_bindings'];
    }

    public function test_every_terminal_forwardable_method_has_a_sample(): void
    {
        $samples = $this->samples();
        $terminal = [];

        foreach (Query::FORWARDABLE as $name) {
            $type = (string) (new ReflectionMethod(Query::class, $name))->getReturnType();

            if (in_array($name, self::NOT_TERMINAL, true) || in_array($name, $this->sql_text_methods(), true) || 'static' === $type) {
                continue;
            }

            $terminal[] = $name;
        }

        $this->assert_true([] !== $terminal);
        $this->assert_equals([], array_values(array_diff($terminal, array_keys($samples))), 'a terminal Query method has no guard sample');
    }

    public function test_every_terminal_query_method_is_scoped(): void
    {
        foreach ($this->samples() as $name => $run) {
            if (in_array($name, ['update', 'delete'], true)) {
                continue;
            }

            Author::transaction(function () use ($name, $run): bool {
                $this->assert_all_scoped($this->data_statements($run), $name);

                return false;
            });
        }
    }

    public function test_builder_writes_are_scoped(): void
    {
        foreach (['update', 'delete'] as $name) {
            Author::transaction(function () use ($name): bool {
                $this->assert_all_scoped($this->data_statements($this->samples()[$name]), $name);

                return false;
            });
        }
    }

    public function test_sql_text_methods_carry_the_scope(): void
    {
        $q = GlobalScopedAuthor::new_query();
        $this->assert_true($this->has_predicate(str_replace(['`', '"'], '', $q->to_sql())));
        $this->assert_same(1, preg_match("/author_id\\s*!=\\s*'?2'?/", str_replace(['`', '"'], '', $q->to_raw_sql())));
        $this->assert_equals([2], $q->get_bindings());
    }

    public function test_model_statics_forward_to_the_scoped_builder(): void
    {
        $this->assert_all_scoped($this->data_statements(fn() => GlobalScopedAuthor::get()), 'get');
        $this->assert_all_scoped($this->data_statements(fn() => GlobalScopedAuthor::where('author_id', '>', 0)->get()), 'where');
        $this->assert_all_scoped($this->data_statements(fn() => GlobalScopedAuthor::pluck('name')), 'pluck');
    }

    /**
     * @return array<string, callable(): mixed>
     */
    private function legacy_entries(): array
    {
        return [
            'find(id)' => fn() => GlobalScopedAuthor::find(1),
            'find(ids)' => fn() => GlobalScopedAuthor::find(1, 3),
            'find(all)' => fn() => GlobalScopedAuthor::find('all'),
            'find(first)' => fn() => GlobalScopedAuthor::find('first'),
            'find(last)' => fn() => GlobalScopedAuthor::find('last'),
            'find(all, conditions)' => fn() => GlobalScopedAuthor::find('all', ['conditions' => ['name' => 'Tito']]),
            'first' => fn() => GlobalScopedAuthor::first(),
            'last' => fn() => GlobalScopedAuthor::last(),
            'all' => fn() => GlobalScopedAuthor::all(),
            'find_by_name' => fn() => GlobalScopedAuthor::find_by_name('Tito'),
            'find_all_by_name' => fn() => GlobalScopedAuthor::find_all_by_name('Tito'),
            'find_by_name_and_author_id' => fn() => GlobalScopedAuthor::find_by_name_and_author_id('Tito', 1),
            'count_by_name' => fn() => GlobalScopedAuthor::count_by_name('Tito'),
            'count' => fn() => GlobalScopedAuthor::count(),
            'count(conditions)' => fn() => GlobalScopedAuthor::count(['conditions' => ['name' => 'Tito']]),
            'exists' => fn() => GlobalScopedAuthor::exists(1),
            'exists(conditions)' => fn() => GlobalScopedAuthor::exists(['conditions' => ['name' => 'Tito']]),
            'update_all' => fn() => GlobalScopedAuthor::update_all(['set' => ['name' => 'x']]),
            'update_all(conditions)' => fn() => GlobalScopedAuthor::update_all(['set' => ['name' => 'x'], 'conditions' => ['name' => 'Tito']]),
            'delete_all' => fn() => GlobalScopedAuthor::delete_all(),
            'delete_all(conditions)' => fn() => GlobalScopedAuthor::delete_all(['conditions' => ['name' => 'Tito']]),
            'Table::find' => fn() => GlobalScopedAuthor::table()->find(['conditions' => ['name' => 'Tito']]),
            'Table::exists' => fn() => GlobalScopedAuthor::table()->exists(['conditions' => ['name' => 'Tito']]),
        ];
    }

    public function test_scoped_legacy_entries_carry_the_predicate(): void
    {
        foreach ($this->legacy_entries() as $name => $run) {
            Author::transaction(function () use ($name, $run): bool {
                $this->assert_all_scoped($this->data_statements($run), $name);

                return false;
            });
        }
    }

    public function test_reload_is_unscoped(): void
    {
        $author = GlobalScopedAuthor::find(1);
        $statements = $this->data_statements(fn() => $author->reload());
        $this->assert_true([] !== $statements);

        foreach ($statements as $sql) {
            $this->assert_false($this->has_predicate($sql), "reload() is unscoped: $sql");
        }
    }

    public function test_unscoped_save_delete_and_table_writes_carry_no_predicate(): void
    {
        Author::transaction(function (): bool {
            $author = GlobalScopedAuthor::find(1);

            $statements = $this->plain(function () use (&$author): void {
                $author->name = 'renamed';
                $author->save();
            });
            $this->assert_true([] !== $statements);

            foreach ($statements as $sql) {
                $this->assert_false($this->has_predicate($sql), "save() update is unscoped: $sql");
            }

            $created = null;
            $statements = $this->plain(function () use (&$created): void {
                $created = new GlobalScopedAuthor(['name' => 'new one']);
                $created->save();
            });
            $this->assert_true([] !== $statements);

            foreach ($statements as $sql) {
                $this->assert_false($this->has_predicate($sql), "save() insert is unscoped: $sql");
            }

            $statements = $this->plain(fn() => $created->delete());
            $this->assert_true([] !== $statements);

            foreach ($statements as $sql) {
                $this->assert_false($this->has_predicate($sql), "delete() is unscoped: $sql");
            }

            // a hidden row is still updated and deleted through its own pk: the scope never limits these
            $hidden = Author::find(2);
            $table = GlobalScopedAuthor::table();
            $statements = $this->plain(function () use ($table): void {
                $data = ['name' => 'hidden'];
                $table->update($data, ['author_id' => 2]);
            });
            $this->assert_true([] !== $statements);

            foreach ($statements as $sql) {
                $this->assert_false($this->has_predicate($sql), "Table::update() is unscoped: $sql");
            }

            $this->assert_same('hidden', Author::find(2)->name);

            $statements = $this->plain(fn() => $table->delete(['author_id' => 2]));
            $this->assert_true([] !== $statements);

            foreach ($statements as $sql) {
                $this->assert_false($this->has_predicate($sql), "Table::delete() is unscoped: $sql");
            }

            $this->assert_null(Author::find_by_author_id(2));
            $this->assert_not_null($hidden);

            return false;
        });
    }

    public function test_insert_and_upsert_carry_no_predicate(): void
    {
        Author::transaction(function (): bool {
            $statements = $this->plain(fn() => GlobalScopedAuthor::new_query()->insert(['name' => 'inserted']));
            $this->assert_true([] !== $statements);

            foreach ($statements as $sql) {
                $this->assert_false($this->has_predicate($sql), "insert is unscoped: $sql");
            }

            $statements = $this->plain(function (): void {
                $data = ['name' => 'inserted too'];
                GlobalScopedAuthor::table()->insert($data);
            });
            $this->assert_true([] !== $statements);

            foreach ($statements as $sql) {
                $this->assert_false($this->has_predicate($sql), "Table::insert() is unscoped: $sql");
            }

            return false;
        });
    }

    public function test_upsert_carries_no_predicate(): void
    {
        Author::transaction(function (): bool {
            $statements = $this->plain(fn() => GlobalScopedAuthor::new_query()->upsert([['author_id' => 2, 'name' => 'upserted']], 'author_id', ['name']));
            $this->assert_true([] !== $statements);

            foreach ($statements as $sql) {
                $this->assert_false($this->has_predicate($sql), "upsert is unscoped: $sql");
            }

            $this->assert_same('upserted', Author::find(2)->name);

            return false;
        });
    }

    public function test_options_to_sql_is_unscoped(): void
    {
        $sql = str_replace(['`', '"'], '', GlobalScopedAuthor::table()->options_to_sql(['conditions' => ['name' => 'Tito']])->to_s());
        $this->assert_false($this->has_predicate($sql), $sql);
        $this->assert_false(str_contains($sql, 'author_id'), $sql);
    }

    public function test_where_group_state_is_unscoped(): void
    {
        $seen = [];
        $query = GlobalScopedAuthor::where(function (Query $group) use (&$seen): void {
            $seen[] = $group->state()->scopes;
            $group->where('name', 'Tito');
        });
        $this->assert_same([null], $seen);

        $or = GlobalScopedAuthor::where('author_id', 1)->or_where(function (Query $group) use (&$seen): void {
            $seen[] = $group->state()->scopes;
            $group->where('name', 'Tito');
        });
        $this->assert_same([null, null], $seen);

        // the outer query is still scoped
        $this->assert_all_scoped($this->data_statements(fn() => $query->get()), 'where group, outer');
        $this->assert_all_scoped($this->data_statements(fn() => $or->get()), 'or_where group, outer');
    }

    public function test_new_query_override_and_direct_construction_are_scoped(): void
    {
        $this->assert_all_scoped($this->data_statements(fn() => (new Query(GlobalScopedAuthor::class))->get()), 'new Query(class)');

        $override = new class extends GlobalScopedAuthor {
            public static function new_query(): Query
            {
                return new Query(GlobalScopedAuthor::class);
            }
        };
        $class = $override::class;
        $this->assert_all_scoped($this->data_statements(fn() => $class::new_query()->where('name', 'Tito')->get()), 'new_query() override');
    }

    public function test_iteration_statements_all_carry_the_scope(): void
    {
        $statements = $this->data_statements(function (): void {
            GlobalScopedAuthor::new_query()->chunk(1, fn() => null);
        });
        $this->assert_true(count($statements) >= 3);
        $this->assert_all_scoped($statements, 'chunk');

        $statements = $this->data_statements(function (): void {
            iterator_to_array(GlobalScopedAuthor::new_query()->lazy(1), false);
        });
        $this->assert_true(count($statements) >= 3);
        $this->assert_all_scoped($statements, 'lazy');

        // chunk_by_id sends a MAX probe first (when it is bounded by the table's current maximum): it is scoped too
        $statements = $this->data_statements(function (): void {
            GlobalScopedAuthor::new_query()->chunk_by_id(1, fn() => null);
        });
        $this->assert_true(count($statements) >= 3);
        $this->assert_all_scoped($statements, 'chunk_by_id');
        $this->assert_true([] !== array_filter($statements, static fn(string $s): bool => 1 === preg_match('/\bMAX\(/i', $s)), 'chunk_by_id sent a MAX probe');

        $statements = $this->data_statements(function (): void {
            iterator_to_array(GlobalScopedAuthor::new_query()->lazy_by_id(1), false);
        });
        $this->assert_true(count($statements) >= 3);
        $this->assert_all_scoped($statements, 'lazy_by_id');

        $statements = $this->data_statements(function (): void {
            iterator_to_array(GlobalScopedAuthor::new_query()->cursor(), false);
        });
        $this->assert_all_scoped($statements, 'cursor');

        // keyset paging over a query that holds an or_where: the scope group still sits outside the user's OR
        $statements = $this->data_statements(function (): void {
            GlobalScopedAuthor::where('author_id', 1)->or_where('author_id', 2)->or_where('author_id', 3)->chunk_by_id(1, fn() => null);
        });
        $this->assert_true(count($statements) >= 2);
        $this->assert_all_scoped($statements, 'chunk_by_id with or_where');
        $seen = [];
        GlobalScopedAuthor::where('author_id', 1)->or_where('author_id', 2)->or_where('author_id', 3)->chunk_by_id(1, function (array $rows) use (&$seen): void {
            foreach ($rows as $row) {
                $seen[] = (int) $row->author_id;
            }
        });
        $this->assert_same([1, 3], $seen);
    }
}
