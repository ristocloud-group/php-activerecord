<?php

use ActiveRecord\ActiveRecordException;

/**
 * Query::cursor(): the models of one SELECT, hydrated one row at a time (spec §4.3, buffered).
 *
 * Fixture data: authors 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob; dated_counts (2026-01-01, 1 hit) and
 * (2026-01-02, 2 hits).
 */
class QueryCursorTest extends DatabaseTest
{
    public function test_cursor_yields_same_models_as_get()
    {
        $got = Author::order_by('author_id')->get();
        $models = iterator_to_array(Author::order_by('author_id')->cursor());

        $this->assert_same([0, 1, 2, 3], array_keys($models));
        $this->assert_same(
            array_map(fn($m) => $m->attributes(), $got),
            array_map(fn($m) => $m->attributes(), $models)
        );
    }

    public function test_cursor_runs_no_sql_before_iteration()
    {
        $before = Author::table()->last_sql;
        $c = Author::where('author_id', 2)->cursor();

        $this->assert_same($before, Author::table()->last_sql);
        $this->assert_same(2, $c->current()->author_id);
    }

    public function test_cursor_with_eager_load_throws()
    {
        $this->expectException(ActiveRecordException::class);
        $this->expectExceptionMessage('cursor() cannot eager load relationships (with()); use lazy() instead');

        Author::with('books')->cursor();
    }

    public function test_cursor_with_legacy_include_throws()
    {
        $this->expectException(ActiveRecordException::class);
        $this->expectExceptionMessage('cursor() cannot eager load');

        ActiveRecord\Query::from_options(Author::table(), ['include' => 'books'])->cursor();
    }

    public function test_buffered_cursor_allows_other_queries()
    {
        $names = [];

        foreach (Author::order_by('author_id')->cursor() as $author) {
            $names[] = Author::find(1)->name;
        }

        $this->assert_same(4, count($names));
    }

    public function test_cursor_readonly()
    {
        $count = 0;

        foreach (Author::new_query()->readonly()->cursor() as $author) {
            $this->assert_true($author->is_readonly());
            ++$count;
        }

        $this->assert_same(4, $count);
    }

    public function test_cursor_binds_dates()
    {
        $models = iterator_to_array(DatedCount::where('day', new ActiveRecord\DateTime('2026-01-02'))->cursor());

        $this->assert_same(1, count($models));
    }

    public function test_cursor_forwarded()
    {
        $this->assert_same(4, count(iterator_to_array(Author::cursor())));
    }

    public function test_cursor_is_a_snapshot_of_the_query_at_the_call()
    {
        $q = Author::where('author_id', 1);
        $c = $q->cursor();
        $q->where('author_id', 2);

        $this->assert_same([1], array_map(fn($m) => $m->author_id, iterator_to_array($c)));
    }
}
