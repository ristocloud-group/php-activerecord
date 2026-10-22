<?php

use ActiveRecord\Table;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The SQL and the bind values of every legacy entry point, pinned per connection in
 * test/golden/<connection>.json: the byte-identical gate for the query builder core.
 * Each case calls the library as a consumer does and compares the statements it sent to
 * the database (see GoldenStatements) with the recorded ones.
 *
 * A case that fails today records the exception it throws. Its entry is re-recorded in the
 * commit that makes it work, as is the entry of any case whose statement changes on purpose.
 *
 * To record, on one connection, every case or those a --filter selects (the entries of the
 * other cases stay as they are):
 *
 *     docker compose exec -e PHPAR_CONNECTION=<connection> -e PHPAR_GOLDEN_RECORD=1 tests \
 *         vendor/bin/phpunit test/GoldenSqlTest.php [--filter '/"(count_alias_hash|exists_alias_hash)"$/']
 */
class GoldenSqlTest extends DatabaseTest
{
    /**
     * The models the corpus reads, loaded before each case: loading a Table reads the
     * schema through Connection::query() too, and that is not what a case pins.
     *
     * @var list<class-string<ActiveRecord\Model>>
     */
    private const array MODELS = [Author::class, Book::class, Venue::class, Host::class, Event::class, OddColumn::class, DatedCount::class, BigId::class];

    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);

        foreach (self::MODELS as $model) {
            $model::table();
            // a relationship names its target class with a leading backslash (the global
            // namespace), under which Table keeps another instance of the model's table
            Table::load("\\$model");
        }
    }

    public function test_mask_replaces_only_recent_datetimes(): void
    {
        $now = date('Y-m-d H:i:s');
        $masked = GoldenStatements::mask([['sql' => 'x', 'values' => [$now, "$now UTC", '2009-01-01 10:00:00', 7, null]]]);
        $this->assert_same(['<now>', '<now>', '2009-01-01 10:00:00', 7, null], $masked[0]['values']);
    }

    public function test_mask_window_is_a_day_either_side_of_now(): void
    {
        $at = fn(int $seconds): string => date('Y-m-d H:i:s', time() + $seconds);
        [$day_ago, $day_ahead, $two_days_ago, $two_days_ahead] = [$at(-82800), $at(82800), $at(-172800), $at(172800)];

        $masked = GoldenStatements::mask([['sql' => 'x', 'values' => [$day_ago, $day_ahead, $two_days_ago, $two_days_ahead]]]);
        $this->assert_same(['<now>', '<now>', $two_days_ago, $two_days_ahead], $masked[0]['values']);
    }

    public function test_capture_reads_the_binds_as_they_are_logged(): void
    {
        // an array of references, as Author::count(1, 2) passes: where PDO binds every value as a
        // string (MySQL, MariaDB, Postgres) it converts them in place, after the statement was logged
        [$one, $two] = [1, 2];
        $values = [&$one, &$two];

        $statements = GoldenStatements::capture(fn() => $this->conn->query('SELECT 1 WHERE 1 IN (?, ?)', $values));
        $this->assert_same([1, 2], $statements[0]['values']);
    }

    public function test_capture_records_an_exception_without_its_file_and_trace(): void
    {
        // a DatabaseException made from a PDOException holds that exception's string form
        $statements = GoldenStatements::capture(function (): void {
            throw new ActiveRecord\DatabaseException(new PDOException('SQLSTATE[42S22]: Column not found'));
        });

        $this->assert_same([['exception' => ActiveRecord\DatabaseException::class, 'message' => 'PDOException: SQLSTATE[42S22]: Column not found']], $statements);
    }

    /**
     * The cases of the connection under test, so that none is skipped.
     *
     * @return array<string, array{string, \Closure(): mixed}>
     */
    public static function cases(): array
    {
        $connection = self::connection_under_test();
        $cases = [];

        foreach (self::corpus() as $name => [$case, $connections]) {
            if (null === $connections || in_array($connection, $connections, true)) {
                $cases[$name] = [$name, $case];
            }
        }

        return $cases;
    }

    /**
     * @param \Closure(): mixed $case
     */
    #[DataProvider('cases')]
    public function test_statements_match_golden(string $name, \Closure $case): void
    {
        $connection = self::connection_under_test();
        $statements = GoldenStatements::mask(GoldenStatements::capture($case));

        // every case sends a statement or fails: nothing captured means the capture saw no connection
        $this->assert_not_empty($statements, "$name: no statement was captured");

        if ('1' === getenv('PHPAR_GOLDEN_RECORD')) {
            $golden = GoldenStatements::load($connection);
            $golden[$name] = $statements;
            GoldenStatements::save($connection, $golden);

            // what was written reads back as it was captured: int, string, float and null survive the JSON
            $this->assert_same($statements, GoldenStatements::load($connection)[$name]);

            return;
        }

        $golden = GoldenStatements::load($connection);
        $this->assert_array_has_key($name, $golden, "test/golden/$connection.json has no entry for $name: record it with PHPAR_GOLDEN_RECORD=1");
        $this->assert_same($golden[$name], $statements);
    }

    private static function connection_under_test(): string
    {
        return getenv('PHPAR_CONNECTION') ?: 'mysql';
    }

    /**
     * name => [the call, the connections it runs on (null: every one)]
     *
     * Fixtures: authors 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob; books 1 and 2;
     * venues 1, 2, 6, 7, 8, 9; hosts 1 to 4; events 1, 2, 3, 5, 6, 7; no odd_columns;
     * dated_counts 2026-01-01 and 2026-01-02.
     *
     * @return array<string, array{\Closure(): mixed, list<string>|null}>
     */
    private static function corpus(): array
    {
        $mysql_family = ['mysql', 'mariadb'];

        return [
            // find by primary key
            'find_pk' => [fn() => Author::find(1), null],
            'find_pk_list' => [fn() => Author::find(1, 2), null],
            'find_pk_with_conditions' => [fn() => Author::find(1, ['conditions' => ['name' => 'Tito']]), null],
            'find_pk_with_alias_conditions' => [fn() => Venue::find(1, ['conditions' => ['marquee' => 'Blender Theater at Gramercy']]), null],
            // RecordNotFound: recorded as the exception (Postgres refuses 'abc' for an INTEGER pk before any SQL)
            'find_pk_non_integer' => [fn() => Author::find('abc'), null],

            // first, last, all
            'find_first_every_window' => [fn() => Author::find('first', ['conditions' => ['name' => 'Tito'], 'order' => 'name desc', 'limit' => 5, 'offset' => 1]), null],
            'find_first_pks' => [fn() => Author::find('first', [2, 3]), null],
            'last_default' => [fn() => Author::last(), null],
            'last_with_order' => [fn() => Author::last(['order' => 'name asc, author_id desc']), null],
            'all_every_option' => [fn() => Author::all([
                'select' => 'author_id, name',
                'conditions' => ['name IN(?)', ['Tito', 'Uncle Bob']],
                'order' => 'name',
                'limit' => 2,
                'offset' => 1,
                'group' => 'author_id, name',
                'having' => 'COUNT(*) > 0',
                'readonly' => true,
            ]), null],
            'all_from' => [fn() => Author::all(['from' => 'authors', 'conditions' => 'author_id > 1']), null],
            'all_string_conditions' => [fn() => Author::all(['conditions' => "name = 'Tito'"]), null],
            'all_hash_null_in_list' => [fn() => Author::all(['conditions' => ['parent_author_id' => [1, null]]]), null],
            'all_hash_empty_list' => [fn() => Author::all(['conditions' => ['author_id' => []]]), null],
            'all_hash_alias' => [fn() => Venue::all(['conditions' => ['marquee' => 'Warner Theatre']]), null],
            'all_hash_real_columns' => [fn() => OddColumn::all(['conditions' => ['space_name' => 'a', 'camelcase' => 'b']]), null],
            'all_hash_date_column' => [fn() => DatedCount::all(['conditions' => ['day' => new DateTime('2026-01-02')]]), null],
            'all_positional_date' => [fn() => DatedCount::all(['conditions' => ['day = ?', new DateTime('2026-01-02')]]), null],
            'all_limit_zero' => [fn() => Author::all(['limit' => 0]), null],
            'all_offset_only' => [fn() => Author::all(['offset' => 2]), null],

            // joins
            'joins_assoc' => [fn() => Book::all(['joins' => ['author'], 'conditions' => ['authors.name' => 'Tito']]), null],
            'joins_assoc_model_key' => [fn() => Book::all(['joins' => ['author'], 'conditions' => ['name' => 'Ancient Art of Main Tanking']]), null],
            // a key that is no column of the model (parent_author_id is the joined authors') stays unqualified; on master
            // it was qualified with the model's table, and the call failed
            'joins_assoc_non_model_key' => [fn() => Book::all(['joins' => ['author'], 'conditions' => ['parent_author_id' => 3]]), null],
            'joins_raw' => [fn() => Book::all(['joins' => 'INNER JOIN authors a ON a.author_id = books.Author_Id']), null],

            // dynamic finders and raw SQL
            'dynamic_find_by' => [fn() => Author::find_by_name('Tito'), null],
            'dynamic_find_all_by' => [fn() => Author::find_all_by_name_and_author_id('Tito', 1), null],
            'dynamic_count_by' => [fn() => Author::count_by_name('Tito'), null],
            'find_by_sql' => [fn() => Author::find_by_sql('SELECT * FROM authors WHERE author_id = ?', [1]), null],

            // count and exists
            'count_plain' => [fn() => Author::count(), null],
            'count_conditions' => [fn() => Author::count(['conditions' => ['name' => 'Tito']]), null],
            'count_pks' => [fn() => Author::count(1, 2), null],
            'count_bare_hash' => [fn() => Author::count(['name' => 'Tito']), null],
            'count_group' => [fn() => Author::count(['group' => 'parent_author_id']), null],
            // fail on master: only find() resolves an alias (change in Task 4)
            'count_alias_hash' => [fn() => Venue::count(['conditions' => ['marquee' => 'Warner Theatre']]), null],
            'exists_alias_hash' => [fn() => Venue::exists(['conditions' => ['marquee' => 'Warner Theatre']]), null],
            'exists_pk' => [fn() => Author::exists(1), null],
            'exists_conditions' => [fn() => Author::exists(['conditions' => ['name' => 'Tito']]), null],

            // eager and lazy loads
            'include_has_many' => [fn() => Author::all(['include' => ['books']]), null],
            'include_nested' => [fn() => Book::all(['include' => ['author' => ['books']]]), null],
            'include_through' => [fn() => Host::find(1, ['include' => ['venues']]), null],
            'lazy_has_many' => [fn() => Author::find(1)->books, null],

            // update_all and delete_all (the hash and positional forms of update_all change in Task 8)
            'update_all_hash' => [fn() => Author::update_all(['set' => ['name' => 'X'], 'conditions' => ['author_id' => 1]]), null],
            'update_all_string_set' => [fn() => Author::update_all(['set' => "name = 'X'", 'conditions' => 'author_id = 1']), null],
            'update_all_positional_limit_order' => [fn() => Author::update_all(['set' => ['name' => 'X'], 'conditions' => ['author_id > ?', 1], 'order' => 'author_id', 'limit' => 1]), null],
            // fail on master: only find() resolves an alias (change in Task 8)
            'update_all_alias_hash' => [fn() => Venue::update_all(['set' => ['state' => 'XX'], 'conditions' => ['marquee' => 'Warner Theatre']]), null],
            'delete_all_alias_hash' => [fn() => Venue::delete_all(['conditions' => ['marquee' => 'Warner Theatre']]), null],
            'delete_all_hash' => [fn() => Author::delete_all(['conditions' => ['author_id' => 1]]), null],
            'delete_all_positional' => [fn() => Author::delete_all(['conditions' => ['author_id > ?', 1]]), null],
            'delete_all_string' => [fn() => Author::delete_all(['conditions' => 'author_id = 1']), null],
            'delete_all_limit_order' => [fn() => Author::delete_all(['conditions' => ['author_id > ?', 1], 'order' => 'author_id', 'limit' => 1]), null],

            // writes of a model (Postgres reads the new pk back with RETURNING, #47)
            'save_insert' => [fn() => Author::create(['name' => 'New']), null],
            'save_update' => [function (): void {
                $author = Author::find(1);
                $author->name = 'Y';
                $author->save();
            }, null],
            'model_delete' => [fn() => Author::find(4)->delete(), null],
            // Table::insert() takes its data by reference (changes in Task 7)
            'table_insert_direct' => [function (): void {
                $data = ['name' => 'Direct'];
                Author::table()->insert($data);
            }, null],
            'upsert' => [fn() => Author::upsert([['author_id' => 1, 'name' => 'Up'], ['author_id' => 9, 'name' => 'New']], 'author_id', ['name']), null],

            // BIGINT UNSIGNED beyond PHP_INT_MAX stays an exact string (#44): the INSERT and the find
            'find_bigint_beyond_int_max' => [function (): void {
                BigId::create(['id' => '18446744073709551615', 'note' => 'max']);
                BigId::find('18446744073709551615');
            }, $mysql_family],
        ];
    }
}
