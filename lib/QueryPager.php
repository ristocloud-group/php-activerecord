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
     * The pages of the query, read by key: each page is ordered by $column (descending with $desc) and starts after
     * the last key of the page before, so rows the reader deletes or changes do not move the pages. The query's own
     * limit caps the rows of all the pages; its orders are replaced by the key's (Query has refused any other).
     * Ascending and without $include_new_rows, the rows read stop at the largest key the query matches before the
     * first page, so rows inserted during the reading are not read; no matching row means no page. The last key of
     * a page is the $alias attribute of its last model, exact (an INTEGER beyond PHP_INT_MAX stays its digits).
     *
     * @param string $method The method that reads the pages, for the message of the exception
     * @return \Generator<int, list<TModel>> The page index, from 0, to the models of the page
     * @throws ActiveRecordException after a page whose last model has no $alias attribute, or a null one, before the
     *   page is yielded
     */
    public function keyset_pages(string $method, int $count, string $column, string $alias, bool $desc, bool $include_new_rows): \Generator
    {
        $remaining = $this->query->state()->limit;

        if (0 === $remaining) {
            return;
        }

        $bound = null;

        if (!$desc && !$include_new_rows) {
            $probe = $this->query->clone();
            $probe_state = $probe->state();
            $probe_state->limit = null;
            $probe_state->offset = null;
            $probe_state->orders = [];
            $bound = $probe->max($column);

            if (null === $bound) {
                return;
            }
        }

        $base = $this->query->clone()->reorder($column, $desc ? 'desc' : 'asc');

        if (null !== $bound) {
            $base->where($column, '<=', $bound);
        }

        $last = null;

        for ($index = 0; null === $remaining || $remaining > 0; ++$index) {
            $size = null === $remaining ? $count : min($count, $remaining);
            $query = $base->clone()->limit($size);

            if (null !== $last) {
                $query->where($column, $desc ? '<' : '>', $last);
            }

            $page = $query->get();

            if ([] === $page) {
                return;
            }

            $last = $page[array_key_last($page)]->attributes()[$alias] ?? null;

            if (null === $last) {
                throw new ActiveRecordException(sprintf('%s() was aborted because the [%s] column is not present in the query result', $method, $alias));
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
