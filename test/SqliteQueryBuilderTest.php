<?php

/**
 * The query builder on SQLite: what its adapter writes its own way, and the DATE binds it fixes there.
 */
class SqliteQueryBuilderTest extends DatabaseTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('sqlite');
    }

    // Spec §5.2: SQLite's LIKE ignores the case of ASCII letters; GLOB does not, and has wildcards of its own
    public function test_case_sensitive_like_is_glob_with_translated_pattern(): void
    {
        $q = Author::where_like('name', 'T_t%', true);
        $this->assert_sql_has('name GLOB ?', $q->to_sql());
        $this->assert_same(['T?t*'], $q->get_bindings());
    }

    // Spec §5.2: a DATE column holds '2026-01-02', which a positional DateTime, sent as a date and time, never
    // equals as text; the builder knows the column, and sends a date
    public function test_legacy_positional_date_differs_from_the_builder(): void
    {
        $this->assert_same(0, DatedCount::count(['conditions' => ['day = ?', new DateTime('2026-01-02')]])); // documented (§5.2)
        $this->assert_same(1, count(DatedCount::where('day', new DateTime('2026-01-02'))->get()));
    }

    // GLOB's own wildcards and brackets stand for themselves in a bracket, then LIKE's wildcards become GLOB's
    public function test_glob_patterns_and_the_rows_found(): void
    {
        $this->assert_same('a[[]b][*]c[?]*?', $this->conn->like_pattern('a[b]*c?%_', true));
        $this->assert_same('a[b]*c?%_', $this->conn->like_pattern('a[b]*c?%_', false));

        $this->assert_same('SELECT * FROM `authors` WHERE `name` LIKE ?', Author::where_like('name', 'T_t%')->to_sql());
        $this->assert_same('SELECT * FROM `authors` WHERE `name` NOT GLOB ?', Author::where_not_like('name', 'T%', true)->to_sql());
        $this->assert_same(['T_t%'], Author::where_like('name', 'T_t%')->get_bindings());

        $this->assert_same(1, count(Author::where_like('name', 'T_t%', true)->get()));
        $this->assert_same(0, count(Author::where_like('name', 't_t%', true)->get()));
        $this->assert_same(1, count(Author::where_like('name', 't_t%')->get()));
        $this->assert_same(4, count(Author::where_not_like('name', 't%', true)->get()));
    }

    // Spec §5.2: Laravel's SQLite grammar adds no operator to the base ones, so neither does the adapter
    public function test_no_operator_of_its_own(): void
    {
        $this->assert_same([], $this->conn->extra_operators());

        foreach (['sounds like', 'is distinct from', '@>', 'glob'] as $operator) {
            $this->assert_exception_message_contains("Unknown operator '$operator' in where() on Author", fn() => Author::where('name', $operator, 'x'), ActiveRecord\ActiveRecordException::class);
        }
    }

    // Spec §5.2: in_random_order() is RANDOM() on SQLite, through the adapter's random_function()
    public function test_in_random_order_is_random(): void
    {
        $this->assert_same('RANDOM()', $this->conn->random_function());
        $this->assert_same('SELECT * FROM `authors` ORDER BY RANDOM()', Author::in_random_order()->to_sql());
        $this->assert_same('SELECT * FROM `authors` ORDER BY `name` ASC, RANDOM()', Author::order_by('name')->in_random_order()->to_sql());

        $ids = array_map(fn(Author $author): int => $author->author_id, Author::in_random_order()->get());
        sort($ids);
        $this->assert_same([1, 2, 3, 4], $ids);
    }

    // SQLite reads an alias of the select in a HAVING, so count() and exists() keep the select of a query that has a
    // HAVING (spec §5.3). Parent 2 is the one of more than one author.
    public function test_a_having_on_an_alias_of_the_select_is_counted_and_exists(): void
    {
        $parents = fn(int $authors): ActiveRecord\Query => Author::select('parent_author_id')->select_raw('COUNT(*) AS n')->group_by('parent_author_id')->having('n', '>', $authors);

        $this->assert_same(1, count($parents(1)->get()));
        $this->assert_same(1, $parents(1)->count());
        $this->assert_same(0, $parents(2)->count());
        $this->assert_true($parents(1)->exists());
        $this->assert_false($parents(2)->exists());
        $this->assert_true($parents(2)->doesnt_exist());

        // a HAVING without a GROUP BY: the aggregate makes one row of the rows, and the HAVING names its alias
        $everyone = fn(int $authors): ActiveRecord\Query => Author::select_raw('COUNT(*) AS n')->having('n', '>', $authors);
        $this->assert_same(1, count($everyone(3)->get()));
        $this->assert_same(1, $everyone(3)->count());
        $this->assert_true($everyone(3)->exists());
        $this->assert_false($everyone(4)->exists());
    }

    // SQLite needs a LIMIT before an OFFSET: an offset alone goes with an unbounded one, LIMIT -1 (#130)
    public function test_the_window_is_limit_with_an_offset_before_the_count(): void
    {
        $this->assert_same('SELECT * FROM `authors` LIMIT 2', Author::limit(2)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 1,2', Author::limit(2)->offset(1)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT -1 OFFSET 1', Author::offset(1)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 2,2', Author::for_page(2, 2)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 0', Author::limit(0)->to_sql());
        $this->assert_same('SELECT * FROM `authors` LIMIT 9223372036854775806,1', Author::for_page(PHP_INT_MAX, 1)->to_sql());
    }
}
