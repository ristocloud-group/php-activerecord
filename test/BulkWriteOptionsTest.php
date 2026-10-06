<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\DatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;

/*
 * GH #145: delete_all() read only the 'conditions' key of an options hash and
 * update_all() only 'set' and 'conditions', so any other shape fell through to a
 * statement without WHERE: a bare conditions hash, a positional list, a misspelled
 * 'conditions' key or an int deleted EVERY row, a hash mixing an option with a
 * column applied only the option (a LIMIT on arbitrary rows; every row on
 * Postgres), and a column beside 'set' updated every row. They now read their
 * argument like the finders: a bare hash is the conditions (as in count() and
 * exists()), and an ambiguous argument throws ActiveRecordException before any SQL.
 *
 * Fixture authors: 4 rows, parent_author_id 3, 2, 1, 2
 * (Tito, George W. Bush, Bill Clinton, Uncle Bob).
 */
class BulkWriteOptionsTest extends DatabaseTest
{
    /**
     * Runs $write, which must throw exactly an ActiveRecordException with
     * $message without issuing any SQL.
     */
    private function assert_refused(string $message, callable $write): void
    {
        Author::table(); // load the schema first: only $write may issue SQL from here
        $conn = Author::connection();
        $conn->last_query = 'no query';

        try {
            $write();
            $this->fail("expected ActiveRecordException: $message");
        } catch (ActiveRecordException $e) {
            $this->assert_same(ActiveRecordException::class, get_class($e));
            $this->assert_same($message, $e->getMessage());
        }

        $this->assert_same('no query', $conn->last_query);
    }

    public function test_delete_all_bare_hash_is_conditions()
    {
        $this->assert_equals(2, Author::delete_all(['parent_author_id' => 2]));
        $sql = Author::table()->last_sql;
        $this->assert_equals(2, Author::count());
        $this->assert_sql_has('WHERE', $sql);

        // the very statement of the documented ['conditions' => ...] form
        Author::delete_all(['conditions' => ['parent_author_id' => 2]]);
        $this->assert_same($sql, Author::table()->last_sql);
    }

    public function test_delete_all_positional_list_throws()
    {
        $this->assert_refused(
            "Invalid options for delete_all(): pass positional conditions as ['conditions' => [...]]",
            fn() => Author::delete_all(['name = ?', 'Tito']),
        );
        $this->assert_equals(4, Author::count());
    }

    public function test_delete_all_mixed_hash_throws()
    {
        $this->assert_refused('Unknown key(s): parent_author_id', fn() => Author::delete_all(['parent_author_id' => 2, 'limit' => 1]));
        $this->assert_equals(4, Author::count());
    }

    public function test_delete_all_conditions_plus_unknown_key_throws()
    {
        $this->assert_refused('Unknown key(s): name', fn() => Author::delete_all(['conditions' => ['name' => 'Tito'], 'name' => 'x']));
        $this->assert_equals(4, Author::count());
    }

    public function test_delete_all_misspelled_conditions_key_reaches_the_database()
    {
        try {
            Author::delete_all(['condition' => ['name' => 'Tito']]);
            $this->fail('expected DatabaseException');
        } catch (DatabaseException $e) {
            // a bare hash: 'condition' is a column name, which the database rejects
            $this->assert_sql_has('WHERE condition', Author::table()->last_sql);
        }

        $this->assert_equals(4, Author::count());
    }

    public function test_delete_all_scalar_throws()
    {
        foreach ([2, 0, 2.5, true, false] as $scalar) {
            $this->assert_refused(
                'Invalid options for delete_all(): expected an options hash or a conditions string',
                fn() => Author::delete_all($scalar),
            );
            $this->assert_equals(4, Author::count());
        }
    }

    public function test_update_all_extra_key_beside_set_throws()
    {
        $this->assert_refused('Unknown key(s): name', fn() => Author::update_all(['set' => ['name' => 'X'], 'name' => 'Tito']));
        $this->assert_refused('Unknown key(s): condition', fn() => Author::update_all(['set' => ['name' => 'X'], 'condition' => ['name' => 'Tito']]));
        $this->assert_equals(0, Author::count(['conditions' => ['name' => 'X']]));
    }

    public function test_update_all_without_set_throws_without_warning()
    {
        $warnings = [];
        $this->assert_refused('Updating requires a hash or string.', function () use (&$warnings) {
            // records every PHP warning/notice of this call alone (the schema is already loaded)
            set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
                $warnings[] = $errstr;

                return true;
            });

            try {
                Author::update_all(['name' => 'X']);
            } finally {
                restore_error_handler();
            }
        });

        $this->assert_same([], $warnings);
        $this->assert_equals(0, Author::count(['conditions' => ['name' => 'X']]));
    }

    public function test_update_all_string_throws()
    {
        $this->assert_refused('Updating requires a hash or string.', fn() => Author::update_all("name = 'X'"));
        $this->assert_equals(0, Author::count(['conditions' => ['name' => 'X']]));
    }

    /**
     * @return array<string, array{\Closure(): int}>
     */
    public static function deletes_without_conditions(): array
    {
        return [
            'no argument' => [fn() => Author::delete_all()],
            'null' => [fn() => Author::delete_all(null)],
            'empty array' => [fn() => Author::delete_all([])],
        ];
    }

    #[DataProvider('deletes_without_conditions')]
    public function test_delete_all_without_conditions_still_deletes_every_row(\Closure $delete_all)
    {
        $this->assert_equals(4, $delete_all());
        $this->assert_sql_doesnt_has('WHERE', Author::table()->last_sql);
        $this->assert_equals(0, Author::count());
    }

    /**
     * @return array<string, array{string|array<string, mixed>}>
     */
    public static function documented_tito_conditions(): array
    {
        return [
            'conditions string' => ["name = 'Tito'"],
            'conditions key' => [['conditions' => ['name' => 'Tito']]],
        ];
    }

    /**
     * @param string|array<string, mixed> $options
     */
    #[DataProvider('documented_tito_conditions')]
    public function test_delete_all_documented_conditions_are_unchanged($options)
    {
        $this->assert_equals(1, Author::delete_all($options));
        $this->assert_equals(3, Author::count());
        $this->assert_equals(0, Author::count(['conditions' => ['name' => 'Tito']]));
    }

    public function test_update_all_with_only_set_still_updates_every_row()
    {
        $this->assert_equals(4, Author::update_all(['set' => ['name' => 'Y']]));
        $this->assert_equals(4, Author::count(['conditions' => ['name' => 'Y']]));
    }
}
