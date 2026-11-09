<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The page generators behind the iteration methods of Query (chunk, lazy, ...): each reads the pages of a
 * query as copies of it, one statement for each page, and leaves the query as it is.
 *
 * @internal Serves Query; not a supported API.
 * @template TModel of Model
 */
final class QueryPager
{
    /**
     * @param Query<TModel> $query
     */
    public function __construct(private readonly Query $query) {}

    /**
     * The pages of the query, read by offset: the query's own offset is the start and its own limit caps the rows
     * of all the pages. A query without an order is read in the order of its primary key. Pages are never empty,
     * and the last one is the first to hold fewer rows than $count (no extra query asks for more).
     *
     * @return \Generator<int, list<TModel>> The page index, from 0, to the models of the page
     */
    public function offset_pages(int $count): \Generator
    {
        $state = $this->query->state();
        $start = $state->offset ?? 0;
        $remaining = $state->limit;
        $base = $this->query->clone();

        if ([] === $state->orders) {
            /** @var list<string> $pk */
            $pk = (array) $this->query->table()->pk;
            foreach ($pk as $column) {
                $base->order_by($column);
            }
        }

        for ($index = 0; null === $remaining || $remaining > 0; ++$index) {
            $size = null === $remaining ? $count : min($count, $remaining);
            $page = $base->clone()->offset($start + $index * $count)->limit($size)->get();

            if ([] === $page) {
                return;
            }

            yield $index => $page;

            if (count($page) < $size) {
                return;
            }

            if (null !== $remaining) {
                $remaining -= $size;
            }
        }
    }

    /**
     * The models of the pages, one at a time, keyed from 0 along all the pages. (yield from would repeat the key of
     * each page.)
     *
     * @param \Generator<int, list<TModel>> $pages
     * @return \Generator<int, TModel>
     */
    public function models(\Generator $pages): \Generator
    {
        $key = 0;
        foreach ($pages as $page) {
            foreach ($page as $model) {
                yield $key++ => $model;
            }
        }
    }
}
