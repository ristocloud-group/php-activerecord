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
}
