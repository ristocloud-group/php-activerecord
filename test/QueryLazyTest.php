<?php

use ActiveRecord\ActiveRecordException;

/**
 * Query::lazy(): the models one at a time, read in pages by offset (spec §4.2).
 */
class QueryLazyTest extends DatabaseTest
{
    use CapturesStatements;

    public function test_lazy_yields_models_with_sequential_keys()
    {
        $models = iterator_to_array(Author::lazy(3));

        $this->assert_same([0, 1, 2, 3], array_keys($models));
        $this->assert_same([1, 2, 3, 4], array_map(fn($m) => $m->author_id, array_values($models)));
    }

    public function test_lazy_validates_at_call()
    {
        $this->expectException(ActiveRecordException::class);
        $this->expectExceptionMessage('lazy(): the chunk size must be at least 1, 0 given');

        Author::lazy(0);
    }

    public function test_lazy_runs_no_sql_before_iteration()
    {
        $before = Author::table()->last_sql;
        $g = Author::where('author_id', 1)->lazy();

        $this->assert_same($before, Author::table()->last_sql);
        $this->assert_same(1, $g->current()->author_id);
    }

    // Final review F2: the query is read as it was at the call
    public function test_lazy_is_a_snapshot_of_the_query_at_the_call()
    {
        $q = Author::where('author_id', '<', 4);
        $models = $q->lazy(2);
        $q->where('author_id', 2)->offset(1);

        $this->assert_same([1, 2, 3], array_map(fn($m) => $m->author_id, array_values(iterator_to_array($models))));
    }

    public function test_lazy_stops_reading_when_consumer_stops()
    {
        $statements = $this->statements(function () {
            foreach (Author::lazy(1) as $a) {
                break;
            }
        });

        $on_authors = array_filter($statements, fn($sql) => str_contains($sql, 'authors'));
        $this->assert_same(1, count($on_authors));
    }

    // Pre-publication review: the models of a page are released before the next page is read, so a page switch holds
    // one page of models, not two
    public function test_one_page_is_held_at_a_page_switch()
    {
        foreach (['lazy' => fn() => Author::lazy(2), 'lazy_by_id' => fn() => Author::lazy_by_id(2), 'lazy_by_id_desc' => fn() => Author::lazy_by_id_desc(2)] as $method => $models) {
            $first = null;
            $alive = [];
            $this->statements(function () use ($models, &$first) {
                foreach ($models() as $index => $author) {
                    if (0 === $index) {
                        $first = WeakReference::create($author);
                    }
                }
            }, function () use (&$first, &$alive) {
                if (null !== $first) {
                    $alive[] = null !== $first->get();
                }
            });

            // the second page and the empty third one: 4 authors in pages of 2
            $this->assert_same([false, false], $alive, "$method(): the first page is released before the next is read");
        }
    }
}
