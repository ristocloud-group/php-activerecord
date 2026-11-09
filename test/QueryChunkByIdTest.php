<?php

use ActiveRecord\ActiveRecordException;

/**
 * Query::chunk_by_id() and its kin: the rows in pages read by key, from the last key seen (spec §4.3, §4.6, §5).
 *
 * Fixture data: authors 1 Tito (parent 3), 2 George W. Bush (parent 2), 3 Bill Clinton (parent 1), 4 Uncle Bob
 * (parent 2); books 1 and 2, of authors 1 and 2; big_ids 1 and PHP_INT_MAX; dated_counts 2026-01-01 and 2026-01-02.
 */
class QueryChunkByIdTest extends DatabaseTest
{
    use CapturesStatements;

    /**
     * Runs chunk_by_id() with a callback that records the author_ids of each page.
     *
     * @return array{bool, list<list<int>>}
     */
    private function collect(ActiveRecord\Query $query, int $count, ?string $column = null, ?string $alias = null, string $attribute = 'author_id'): array
    {
        $pages = [];
        $result = $query->chunk_by_id($count, function (array $models) use (&$pages, $attribute) {
            $pages[] = array_map(fn($m) => $m->$attribute, $models);
        }, $column, $alias);

        return [$result, $pages];
    }

    /**
     * Asserts that $run throws an ActiveRecordException whose message contains $message, without sending any statement.
     */
    private function assert_refused_before_any_sql(callable $run, string $message): void
    {
        $statements = $this->statements(function () use ($run, $message) {
            try {
                $run();
                $this->fail('expected an ActiveRecordException');
            } catch (ActiveRecordException $e) {
                $this->assert_string_contains_string($message, $e->getMessage());
            }
        });

        $this->assert_same([], $statements);
    }

    public function test_chunk_by_id_pages_by_key()
    {
        [$result, $pages] = $this->collect(Author::new_query(), 3);

        $this->assert_same([[1, 2, 3], [4]], $pages);
        $this->assert_true($result);
    }

    public function test_chunk_by_id_desc()
    {
        $pages = [];
        $result = Author::chunk_by_id_desc(3, function (array $models) use (&$pages) {
            $pages[] = array_map(fn($m) => $m->author_id, $models);
        });

        $this->assert_same([[4, 3, 2], [1]], $pages);
        $this->assert_true($result);
    }

    public function test_chunk_by_id_stops_on_false()
    {
        $calls = 0;
        $result = Author::chunk_by_id(1, function () use (&$calls) {
            ++$calls;

            return false;
        });

        $this->assert_same(1, $calls);
        $this->assert_false($result);
    }

    public function test_chunk_by_id_survives_deletes_in_loop()
    {
        $seen = [];
        Author::chunk_by_id(1, function (array $models) use (&$seen) {
            foreach ($models as $m) {
                $seen[] = $m->author_id;
                $m->delete();
            }
        });

        $this->assert_same([1, 2, 3, 4], $seen);
        $this->assert_same(0, Author::count());
    }

    public function test_chunk_by_id_survives_updates_of_filtered_column()
    {
        $seen = [];
        Author::where('parent_author_id', 2)->chunk_by_id(1, function (array $models) use (&$seen) {
            foreach ($models as $m) {
                $seen[] = $m->author_id;
                $m->parent_author_id = 9;
                $m->save();
            }
        });

        $this->assert_same([2, 4], $seen);
        $this->assert_same(2, Author::where('parent_author_id', 9)->count());
    }

    public function test_include_new_rows_false_skips_inserted()
    {
        $seen = [];
        Author::chunk_by_id(2, function (array $models, int $page) use (&$seen) {
            if (1 === $page) {
                Author::create(['name' => 'New']);
            }
            foreach ($models as $m) {
                $seen[] = $m->author_id;
            }
        });

        $this->assert_same([1, 2, 3, 4], $seen);
        $this->assert_same(5, Author::count());
    }

    public function test_include_new_rows_true_processes_inserted()
    {
        $seen = [];
        $new = null;
        Author::chunk_by_id(2, function (array $models, int $page) use (&$seen, &$new) {
            if (1 === $page) {
                $new = Author::create(['name' => 'New']);
            }
            foreach ($models as $m) {
                $seen[] = $m->author_id;
            }
        }, include_new_rows: true);

        $this->assert_same(5, count($seen));
        $this->assert_same([1, 2, 3, 4], array_slice($seen, 0, 4));
        $this->assert_same($new->author_id, $seen[4]);
    }

    // Review Focus 4: the bound is the largest key of the rows the query matches at the start, so a row that comes to
    // match it during the run, with a key beyond the bound, is not read
    public function test_bound_is_max_of_filtered_query()
    {
        $seen = [];
        Author::where('parent_author_id', 3)->chunk_by_id(1, function (array $models, int $page) use (&$seen) {
            if (1 === $page) {
                $george = Author::find(2);
                $george->parent_author_id = 3;
                $george->save();
            }
            foreach ($models as $m) {
                $seen[] = $m->author_id;
            }
        });

        $this->assert_same([1], $seen);
        $this->assert_same(2, Author::where('parent_author_id', 3)->count());
    }

    public function test_no_match_reads_nothing()
    {
        [$result, $pages] = $this->collect(Author::where('author_id', '>', 99), 2);

        $this->assert_same([], $pages);
        $this->assert_true($result);
    }

    public function test_insert_into_other_table_in_loop()
    {
        $pages = 0;
        Author::chunk_by_id(1, function () use (&$pages) {
            Book::create(['author_id' => 1, 'name' => 'Inside']);
            ++$pages;
        });

        $this->assert_same(4, $pages);
        $this->assert_same(4, Book::where('name', 'Inside')->count());
    }

    public function test_limit_caps_total()
    {
        [, $pages] = $this->collect(Author::limit(3), 2);

        $this->assert_same([[1, 2], [3]], $pages);
    }

    public function test_limit_zero_reads_nothing()
    {
        $statements = $this->statements(function () use (&$pages, &$result) {
            [$result, $pages] = $this->collect(Author::limit(0), 2);
        });

        $this->assert_same([], $pages);
        $this->assert_true($result);
        $this->assert_same([], $statements);
    }

    public function test_order_on_key_is_replaced()
    {
        [, $pages] = $this->collect(Author::order_by('author_id'), 2);
        $this->assert_same([[1, 2], [3, 4]], $pages);

        [, $pages] = $this->collect(Author::order_by_desc('author_id'), 2);
        $this->assert_same([[1, 2], [3, 4]], $pages);
    }

    public function test_other_order_throws()
    {
        foreach ([Author::order_by('name'), Author::order_by('author_id')->order_by('name'), Author::order_by_raw('author_id'), Author::in_random_order()] as $query) {
            $this->assert_refused_before_any_sql(fn() => $query->chunk_by_id(2, fn() => null), 'chunk_by_id()');
            $this->assert_refused_before_any_sql(fn() => $query->chunk_by_id(2, fn() => null), 'ordered by');
        }
    }

    public function test_offset_throws()
    {
        $this->assert_refused_before_any_sql(fn() => Author::offset(1)->chunk_by_id(2, fn() => null), 'chunk_by_id()');
    }

    public function test_composite_pk_needs_column()
    {
        DatedCount::table(); // its schema read first: the introspection is no statement of the chunk
        $this->assert_refused_before_any_sql(fn() => DatedCount::new_query()->chunk_by_id(1, fn() => null), 'chunk_by_id()');

        $days = [];
        DatedCount::new_query()->chunk_by_id(1, function (array $models) use (&$days) {
            $days[] = array_map(fn($m) => $m->day->format('Y-m-d'), $models);
        }, 'at');

        $this->assert_same([['2026-01-01'], ['2026-01-02']], $days);
    }

    public function test_unknown_column_throws()
    {
        $this->assert_refused_before_any_sql(fn() => Author::chunk_by_id(1, fn() => null, 'nope'), 'chunk_by_id()');
        $this->assert_refused_before_any_sql(fn() => Author::chunk_by_id(1, fn() => null, 'books.author_id'), 'chunk_by_id()');
        $this->assert_refused_before_any_sql(fn() => Author::chunk_by_id(1, fn() => null, 'authors.nope'), 'chunk_by_id()');
    }

    public function test_size_below_one_throws()
    {
        foreach ([0, -1] as $size) {
            $this->assert_refused_before_any_sql(fn() => Author::chunk_by_id($size, fn() => null), 'chunk_by_id(): the chunk size must be at least 1');
            $this->assert_refused_before_any_sql(fn() => Author::chunk_by_id_desc($size, fn() => null), 'chunk_by_id_desc(): the chunk size must be at least 1');
        }
    }

    public function test_qualified_key_with_join()
    {
        $query = Author::join('books', 'books.author_id', '=', 'authors.author_id')->select('authors.*');
        [, $pages] = $this->collect($query, 1, 'authors.author_id');

        $this->assert_same([[1], [2]], $pages);
    }

    public function test_alias_select()
    {
        [, $pages] = $this->collect(Author::select('author_id as aid', 'name'), 2, 'author_id', 'aid', 'aid');

        $this->assert_same([[1, 2], [3, 4]], $pages);
    }

    public function test_missing_alias_throws_after_first_page()
    {
        $calls = 0;

        try {
            Author::select('name')->chunk_by_id(2, function () use (&$calls) {
                ++$calls;
            });
            $this->fail('expected an ActiveRecordException');
        } catch (ActiveRecordException $e) {
            $this->assert_string_contains_string('[author_id] column is not present in the query result', $e->getMessage());
        }

        $this->assert_same(0, $calls);
    }

    // Review Focus 1: the key named by an alias of the model is read back from the attribute it stands for
    public function test_aliased_key_column()
    {
        [, $pages] = $this->collect(KeysetAliasAuthor::new_query(), 2, 'ref');

        $this->assert_same([[1, 2], [3, 4]], $pages);
    }

    public function test_big_int_max_key()
    {
        [, $pages] = $this->collect(BigId::new_query(), 1, attribute: 'id');

        $this->assert_same([[1], [PHP_INT_MAX]], $pages);
    }

    public function test_lazy_by_id_and_desc()
    {
        $models = iterator_to_array(Author::lazy_by_id(3));
        $this->assert_same([0, 1, 2, 3], array_keys($models));
        $this->assert_same([1, 2, 3, 4], array_map(fn($m) => $m->author_id, $models));

        $models = iterator_to_array(Author::lazy_by_id_desc(3));
        $this->assert_same([0, 1, 2, 3], array_keys($models));
        $this->assert_same([4, 3, 2, 1], array_map(fn($m) => $m->author_id, $models));

        $this->assert_refused_before_any_sql(fn() => Author::lazy_by_id(0), 'lazy_by_id(): the chunk size must be at least 1');
        $this->assert_refused_before_any_sql(fn() => Author::lazy_by_id_desc(0), 'lazy_by_id_desc(): the chunk size must be at least 1');
        $this->assert_refused_before_any_sql(fn() => Author::order_by('name')->lazy_by_id(2), 'lazy_by_id()');
    }

    public function test_lazy_by_id_include_new_rows()
    {
        $seen = [];
        foreach (Author::lazy_by_id(2, include_new_rows: true) as $author) {
            if (1 === $author->author_id) {
                Author::create(['name' => 'New']);
            }
            $seen[] = $author->author_id;
        }

        $this->assert_same(5, count($seen));
    }

    public function test_chunk_by_id_leaves_query_unchanged()
    {
        $q = Author::where('author_id', '>', 1)->limit(3);
        $before = $q->to_sql();
        $q->chunk_by_id(1, fn() => null);

        $this->assert_same($before, $q->to_sql());
    }
}
