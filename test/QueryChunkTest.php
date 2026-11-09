<?php

use ActiveRecord\ActiveRecordException;

/**
 * Query::chunk(): the rows in pages read by offset (spec §4.2, §4.6).
 */
class QueryChunkTest extends DatabaseTest
{
    use CapturesStatements;

    /**
     * Runs the chunk with a callback that records the author_ids of each page and the page numbers.
     *
     * @return array{bool, list<list<int>>, list<int>}
     */
    private function collect(ActiveRecord\Query $query, int $count): array
    {
        $pages = [];
        $numbers = [];
        $result = $query->chunk($count, function (array $models, int $page) use (&$pages, &$numbers) {
            $pages[] = array_map(fn($m) => $m->author_id, $models);
            $numbers[] = $page;
        });

        return [$result, $pages, $numbers];
    }

    public function test_chunk_pages_in_pk_order_without_order()
    {
        [$result, $pages, $numbers] = $this->collect(Author::new_query(), 3);

        $this->assert_same([[1, 2, 3], [4]], $pages);
        $this->assert_same([1, 2], $numbers);
        $this->assert_true($result);
    }

    public function test_chunk_keeps_existing_order()
    {
        [, $pages] = $this->collect(Author::order_by_desc('author_id'), 3);

        $this->assert_same([[4, 3, 2], [1]], $pages);
    }

    public function test_chunk_honors_offset_and_limit()
    {
        [, $pages] = $this->collect(Author::order_by('author_id')->offset(1)->limit(2), 1);

        $this->assert_same([[2], [3]], $pages);
    }

    public function test_chunk_limit_zero_reads_nothing()
    {
        [$result, $pages] = $this->collect(Author::limit(0), 2);

        $this->assert_same([], $pages);
        $this->assert_true($result);
    }

    public function test_chunk_exact_multiple_has_no_empty_page()
    {
        $calls = 0;
        Author::chunk(2, function () use (&$calls) {
            ++$calls;
        });

        $this->assert_same(2, $calls);
    }

    public function test_chunk_stops_on_false()
    {
        $calls = 0;
        $result = Author::chunk(1, function () use (&$calls) {
            ++$calls;

            return false;
        });

        $this->assert_same(1, $calls);
        $this->assert_false($result);
    }

    public function test_chunk_no_rows()
    {
        [$result, $pages] = $this->collect(Author::where('author_id', '>', 99), 2);

        $this->assert_same([], $pages);
        $this->assert_true($result);
    }

    public function test_chunk_composite_pk_implicit_order()
    {
        $days = [];
        DatedCount::chunk(1, function (array $models) use (&$days) {
            foreach ($models as $m) {
                $days[] = $m->day->format('Y-m-d');
            }
        });

        $this->assert_same(['2026-01-01', '2026-01-02'], $days);
        $this->assert_true(1 === preg_match('/ORDER BY\s+\S*day\S*\s+ASC,\s*\S*\bat\b\S*\s+ASC/i', DatedCount::table()->last_sql), DatedCount::table()->last_sql);
    }

    public function test_chunk_join_qualifies_implicit_order()
    {
        $query = Author::join('books', 'books.author_id', '=', 'authors.author_id')->select('authors.*');
        [, $pages] = $this->collect($query, 10);

        $this->assert_same([[1, 2]], $pages);
    }

    public function test_chunk_leaves_query_unchanged()
    {
        $q = Author::where('author_id', '>', 1);
        $before = $q->to_sql();
        $q->chunk(1, fn() => null);

        $this->assert_same($before, $q->to_sql());
    }

    public function test_chunk_size_below_one_throws()
    {
        foreach ([0, -1] as $size) {
            $before = Author::table()->last_sql;

            try {
                Author::chunk($size, fn() => null);
                $this->fail('expected an ActiveRecordException');
            } catch (ActiveRecordException $e) {
                $this->assert_string_contains_string('chunk(): the chunk size must be at least 1', $e->getMessage());
            }

            $this->assert_same($before, Author::table()->last_sql);
        }
    }

    public function test_chunk_eager_loads_per_page()
    {
        $statements = $this->statements(fn() => Author::with('books')->chunk(2, fn() => null));
        $on_books = array_filter($statements, fn($sql) => 1 === preg_match('/^SELECT .* FROM [`"]?books/', $sql));

        $this->assert_same(2, count($on_books));
    }

    public function test_chunk_readonly()
    {
        $all = [];
        Author::new_query()->readonly()->chunk(2, function (array $models) use (&$all) {
            foreach ($models as $m) {
                $all[] = $m->is_readonly();
            }
        });

        $this->assert_same([true, true, true, true], $all);
    }

    public function test_chunk_forwarded_and_shadowed()
    {
        $this->assert_same(-1, ChunkShadowingAuthor::chunk(2, [], fn() => null));

        [$result, $pages] = $this->collect(ChunkShadowingAuthor::where('author_id', '>', 0), 2);
        $this->assert_true($result);
        $this->assert_same([[1, 2], [3, 4]], $pages);
    }
}
