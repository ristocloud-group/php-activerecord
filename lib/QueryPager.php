<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The page generators behind the iteration methods of Query (chunk, lazy, ...): each reads the pages of a
 * query as copies of it, one statement for each page, and leaves the query as it is. They yield each page by
 * reference and drop it before the next page is read, so a reader that drops its own copy too (see models()) holds
 * one page of models at a page switch, not two: a generator keeps the value it yielded until it yields again.
 *
 * @internal Serves Query; not a supported API.
 * @template TModel of Model
 */
final class QueryPager
{
    /**
     * @param Query<TModel> $query
     * @param Table $table The table of the query's model, which Query keeps private
     */
    public function __construct(private readonly Query $query, private readonly Table $table) {}

    /**
     * The pages of the query, read by offset: the query's own offset is the start and its own limit caps the rows
     * of all the pages. A query without an order is read in the order of its primary key. Pages are never empty.
     * The reading stops after a page shorter than asked for, with no further query; when the rows are an exact
     * multiple of $count, it ends on one more query that reads nothing, and a reading capped by the limit may end on a
     * full page.
     *
     * @return \Generator<int, list<TModel>> The page index, from 0, to the models of the page
     */
    public function &offset_pages(int $count): \Generator
    {
        $state = $this->query->state();
        $start = $state->offset ?? 0;
        $remaining = $state->limit;
        $base = $this->query->clone();

        if ([] === $state->orders) {
            /** @var list<string> $pk */
            $pk = (array) $this->table->pk;
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

            $read = count($page);
            $page = null; // released before the next page is read

            if ($read < $size) {
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
     * a page is the $alias attribute of its last model, exact (an INTEGER beyond PHP_INT_MAX stays its digits). The
     * conditions of the query are ANDed with the key's as one group, in parentheses, so that a top-level OR in them
     * cannot escape the key's.
     *
     * @param string $method The method that reads the pages, for the message of the exception
     * @return \Generator<int, list<TModel>> The page index, from 0, to the models of the page
     * @throws ActiveRecordException after a page whose last model has no $alias attribute, or a null one, or whose last
     *   key is the one of the page before (the next page would read the same rows; two dates are compared by value),
     *   before the page is yielded
     */
    public function &keyset_pages(string $method, int $count, string $column, string $alias, bool $desc, bool $include_new_rows): \Generator
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
        self::enclose_wheres($base->state());

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

            $previous = $last;
            $last = $page[array_key_last($page)]->attributes()[$alias] ?? null;

            if (null === $last) {
                throw new ActiveRecordException(sprintf('%s() was aborted because the [%s] column is not present in the query result', $method, $alias));
            }

            // the next page would read the same rows again, forever (compared strictly: two big keys read as digits
            // must not be compared as the floats they would make; two dates, objects, by value)
            if ($last instanceof \DateTimeInterface && $previous instanceof \DateTimeInterface ? $last == $previous : $last === $previous) {
                $shown = $last instanceof \DateTimeInterface ? var_export($last->format('Y-m-d H:i:s'), true) : var_export($last, true);

                throw new ActiveRecordException(sprintf('%s() was aborted because the key [%s] did not advance past %s', $method, $alias, $shown));
            }

            yield $index => $page;

            $read = count($page);
            $page = null; // released before the next page is read

            if ($read < $size) {
                return;
            }

            if (null !== $remaining) {
                $remaining -= $size;
            }
        }
    }

    /**
     * Encloses the conditions of $state in one group, the one a where(Closure) adds, when there is more than one: the
     * conditions ANDed after them then apply to all of them, not to the last one an OR joins. A lone condition is left
     * as it is: a group or a raw condition beside another is in parentheses already (see ConditionCompiler).
     */
    private static function enclose_wheres(QueryState $state): void
    {
        if (count($state->wheres) < 2) {
            return;
        }

        $group = new QueryState();
        $group->wheres = $state->wheres;
        $state->wheres = [['type' => 'group', 'boolean' => 'AND', 'not' => false, 'group' => $group]];
    }

    /**
     * The models of the pages, one at a time, keyed from 0 along all the pages. (yield from would repeat the key of
     * each page.) A page is released before the next one is read: a foreach would keep it in its variable.
     *
     * @param \Generator<int, list<TModel>> $pages
     * @return \Generator<int, TModel>
     */
    public function models(\Generator $pages): \Generator
    {
        $key = 0;
        while ($pages->valid()) {
            $page = $pages->current();
            foreach ($page as $model) {
                yield $key++ => $model;
            }
            unset($page, $model);
            $pages->next();
        }
    }
}
