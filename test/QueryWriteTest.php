<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\DatabaseException;
use ActiveRecord\Model;
use ActiveRecord\Query;
use ActiveRecord\QueryCompiler;
use ActiveRecord\QueryState;
use ActiveRecord\Table;
use ActiveRecord\Timestamps;
use PHPUnit\Framework\Attributes\DataProvider;

// a backed enum and a pure one: the builder's writes do not take an enum as a value
enum QueryWriteSuit: string
{
    case Hearts = 'H';
}

enum QueryWritePureSuit
{
    case Spades;
}

/**
 * The writes of the query builder (spec §5.5): update(), delete() and insert(). An UPDATE and a DELETE write the rows
 * that the WHERE of the query selects, read by the rules of every read (Conditions and ConditionCompiler), in its order
 * and up to its limit: MySQL, MariaDB and SQLite take ORDER BY and LIMIT on them, and Postgres picks the rows by primary
 * key in a subquery (#92). Every other clause is refused at the call, before a statement is sent, and so is every
 * clause on an INSERT and an UPSERT. No write ever loses its WHERE.
 *
 * Fixture data: authors 1 Tito (parent 3), 2 George W. Bush (parent 2), 3 Bill Clinton (parent 1) and 4 Uncle Bob
 * (parent 2), none with a timestamp, a some_text or an encrypted_password; books 1 and 2, of authors 1 and 2.
 */
class QueryWriteTest extends DatabaseTest
{
    private const string FROZEN = '2026-10-21 10:00:00';

    /** What a refusal of update() and delete() says they take */
    private const string TAKES = 'it takes only where, order_by and limit';

    /**
     * $sql with each backtick replaced by the quote character of the connection.
     */
    private function sql(string $sql): string
    {
        return str_replace('`', $this->conn::$QUOTE_CHARACTER, $sql);
    }

    /**
     * The class and the message of what $call throws, or null when it throws nothing.
     *
     * @return array{class-string<Throwable>, string}|null
     */
    private function thrown(callable $call): ?array
    {
        try {
            $call();
        } catch (Throwable $e) {
            return [$e::class, $e->getMessage()];
        }

        return null;
    }

    /**
     * The author_id of each model, in order.
     *
     * @param list<Model> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        $ids = array_map(fn(Model $model): int => (int) $model->author_id, $models);
        sort($ids);

        return $ids;
    }

    /**
     * The name of every author, by author_id.
     *
     * @return array<int, string>
     */
    private function names_by_id(): array
    {
        $names = [];

        foreach (Author::all(['order' => 'author_id']) as $author) {
            $names[(int) $author->author_id] = $author->name;
        }

        return $names;
    }

    /**
     * The refusal of the write $method of an Author query whose where calls all added no condition, or whose when() or
     * unless() skipped its callback (see QueryCompiler::refuse_empty_where()).
     */
    private function empty_where_refusal(string $method): string
    {
        return sprintf(
            '%s() on Author has where() calls whose conditions all turned out empty (an empty array or list, a closure that adds none), or a when() or unless() that skipped its callback, so it would write every row: it is refused. A write of every row calls no where(): Author::new_query()->%s',
            $method,
            'update' === $method ? 'update(...)' : 'delete()',
        );
    }

    /**
     * The created_at and updated_at of the author named $name, as stored.
     *
     * @return array{created_at: string|null, updated_at: string|null}
     */
    private function stamps(string $name): array
    {
        $author = Author::find('first', ['conditions' => ['name' => $name]]);
        $format = fn(mixed $time): ?string => $time instanceof DateTimeInterface ? $time->format('Y-m-d H:i:s') : null;

        return ['created_at' => $format($author->created_at), 'updated_at' => $format($author->updated_at)];
    }

    public function test_update_and_delete(): void
    {
        ActiveRecord\Timestamps::freeze('2026-10-21 10:00:00');
        $this->assert_same(1, Author::where('author_id', 1)->update(['name' => 'X']));
        $this->assert_equals('2026-10-21 10:00:00', Author::find(1)->updated_at->format('Y-m-d H:i:s'));
        $this->assert_same(1, Author::where('author_id', 4)->delete());
        $this->assert_same(1, Author::where('author_id', '>', 1)->order_by('author_id')->limit(1)->update(['name' => 'L']));
        ActiveRecord\Timestamps::freeze(null);
    }

    public function test_write_refusals(): void
    {
        $this->assert_exception_message_contains('Updating requires a hash or string.', fn() => Author::where('author_id', 1)->update([]), ActiveRecord\ActiveRecordException::class);
        $this->assert_exception_message_contains('cannot use joins', fn() => Book::joins('author')->delete(), ActiveRecord\ActiveRecordException::class);
        $this->assert_exception_message_contains('cannot use offset', fn() => Author::offset(1)->update(['name' => 'x']), ActiveRecord\ActiveRecordException::class);
    }

    public function test_insert_one_and_many_with_timestamps(): void
    {
        ActiveRecord\Timestamps::freeze('2026-10-21 10:00:00');
        $this->assert_true(Author::new_query()->insert([['name' => 'A'], ['name' => 'B']]));
        $this->assert_same(2, Author::where('created_at', '2026-10-21 10:00:00')->count());
        $this->assert_exception_message_contains('same keys', fn() => Author::new_query()->insert([['name' => 'A'], ['parent_author_id' => 1]]), ActiveRecord\ActiveRecordException::class);
        Author::without_timestamps(fn() => Author::new_query()->insert(['name' => 'C']));
        $this->assert_null(Author::find_by_name('C')->created_at);
        ActiveRecord\Timestamps::freeze(null);
    }

    // -- the WHERE of a write

    /**
     * Every form of a condition, and the authors it selects once author 4 has a some_text: none of them is every
     * author, so a WHERE that a write dropped would show.
     *
     * @return array<string, array{Closure(): Query<Author>, list<int>}>
     */
    public static function condition_forms(): array
    {
        return [
            'where(column, value)' => [fn() => Author::where('name', 'Tito'), [1]],
            'where(column, operator, value)' => [fn() => Author::where('author_id', '>=', 3), [3, 4]],
            'where(column, like, pattern)' => [fn() => Author::where('name', 'like', '%Bush'), [2]],
            'where(column, null)' => [fn() => Author::where('some_text', null), [1, 2, 3]],
            'where(column, !=, null)' => [fn() => Author::where('some_text', '!=', null), [4]],
            'or_where' => [fn() => Author::where('name', 'Tito')->or_where('author_id', 4), [1, 4]],
            'where_not' => [fn() => Author::where_not('parent_author_id', 2), [1, 3]],
            'or_where_not' => [fn() => Author::where('author_id', 1)->or_where_not('author_id', '<', 4), [1, 4]],
            'a hash' => [fn() => Author::where(['parent_author_id' => 2]), [2, 4]],
            'a hash of two columns' => [fn() => Author::where(['name' => 'Tito', 'parent_author_id' => 3]), [1]],
            'a hash with a list' => [fn() => Author::where(['author_id' => [1, 3]]), [1, 3]],
            'a hash with an empty list' => [fn() => Author::where(['author_id' => []]), []],
            'a hash with a null in a list' => [fn() => Author::where(['parent_author_id' => [1, null]]), [3]],
            'a hash with null' => [fn() => Author::where(['some_text' => null]), [1, 2, 3]],
            'or_where with a hash' => [fn() => Author::where('author_id', 4)->or_where(['author_id' => 1, 'name' => 'Tito']), [1, 4]],
            'a list' => [fn() => Author::where([['author_id', '>', 1], ['parent_author_id', 2]]), [2, 4]],
            'a hash and a list' => [fn() => Author::where(['parent_author_id' => 2, ['author_id', '>', 2]]), [4]],
            'a closure' => [fn() => Author::where(fn(Query $q) => $q->where('author_id', 1)->or_where('author_id', 2))->where('parent_author_id', 2), [2]],
            'a negated closure' => [fn() => Author::where_not(fn(Query $q) => $q->where('author_id', 1)->or_where('author_id', 2)), [3, 4]],
            'nested closures' => [fn() => Author::where(fn(Query $q) => $q->where(fn(Query $r) => $r->where('author_id', 1)->or_where('author_id', 3))->where('parent_author_id', '!=', 1)), [1]],
            'where_column' => [fn() => Author::where_column('author_id', 'parent_author_id'), [2]],
            'where_column with an operator' => [fn() => Author::where_column('author_id', '>', 'parent_author_id'), [3, 4]],
            'or_where_column' => [fn() => Author::where('author_id', 4)->or_where_column('author_id', 'parent_author_id'), [2, 4]],
            'where_raw' => [fn() => Author::where_raw('author_id > ? AND parent_author_id = ?', [1, 2]), [2, 4]],
            'where_raw with a list' => [fn() => Author::where_raw('author_id IN(?)', [[1, 4]]), [1, 4]],
            'where_raw with an OR beside a condition' => [fn() => Author::where_raw('author_id = 1 OR author_id = 2')->where('parent_author_id', 2), [2]],
            'or_where_raw' => [fn() => Author::where('author_id', 1)->or_where_raw('name = ?', ['Uncle Bob']), [1, 4]],
            'where_in' => [fn() => Author::where_in('author_id', [2, 3]), [2, 3]],
            'where_in with an empty list' => [fn() => Author::where_in('author_id', []), []],
            'where_in with a null' => [fn() => Author::where_in('parent_author_id', [1, null]), [3]],
            'or_where_in' => [fn() => Author::where('author_id', 4)->or_where_in('author_id', [1]), [1, 4]],
            'where_not_in' => [fn() => Author::where_not_in('author_id', [1, 2]), [3, 4]],
            'where_null' => [fn() => Author::where_null('some_text'), [1, 2, 3]],
            'where_not_null' => [fn() => Author::where_not_null('some_text'), [4]],
            'or_where_null' => [fn() => Author::where('author_id', 4)->or_where_null('some_text')->where('author_id', '>', 2), [3, 4]],
            'where_between' => [fn() => Author::where_between('author_id', [2, 3]), [2, 3]],
            'where_not_between' => [fn() => Author::where_not_between('author_id', [2, 3]), [1, 4]],
            'where_between_columns' => [fn() => Author::where_between_columns('author_id', ['parent_author_id', 'author_id']), [2, 3, 4]],
            'where_not_between_columns' => [fn() => Author::where_not_between_columns('author_id', ['parent_author_id', 'author_id']), [1]],
            'where_like' => [fn() => Author::where_like('name', 'u%'), [4]],
            'where_like, case-sensitive' => [fn() => Author::where_like('name', 'T%', true), [1]],
            'where_not_like' => [fn() => Author::where_not_like('name', '%i%'), [2, 4]],
            'where_all' => [fn() => Author::where_all(['author_id', 'parent_author_id'], 2), [2]],
            'where_any' => [fn() => Author::where_any(['author_id', 'parent_author_id'], 1), [1, 3]],
            'where_none' => [fn() => Author::where_none(['author_id', 'parent_author_id'], 2), [1, 3]],
            'where_key' => [fn() => Author::where_key(3), [3]],
            'where_key with a list' => [fn() => Author::where_key([1, 2]), [1, 2]],
            'where_key_not' => [fn() => Author::where_key_not([1, 2]), [3, 4]],
        ];
    }

    /**
     * The UPDATE and the DELETE of a query write the rows its get() reads, whatever form its conditions take: the WHERE
     * is compiled by the one rule of the builder, the same for a read and a write, and no form is dropped. The update
     * sets a column that no condition reads, so that the delete that follows selects the same rows.
     *
     * @param Closure(): Query<Author> $query
     * @param list<int> $ids
     */
    #[DataProvider('condition_forms')]
    public function test_update_and_delete_write_the_rows_their_where_selects(Closure $query, array $ids): void
    {
        Author::update_all(['set' => "some_text = 'x'", 'conditions' => ['author_id' => 4]]);
        $this->assert_same($ids, $this->ids($query()->get()), 'the rows the query selects');

        $this->assert_same(count($ids), $query()->update(['encrypted_password' => 'written']));
        $this->assert_same($ids, $this->ids(Author::all(['conditions' => ['encrypted_password' => 'written']])));

        $this->assert_same(count($ids), $query()->delete());
        $this->assert_same(array_values(array_diff([1, 2, 3, 4], $ids)), $this->ids(Author::all()));
    }

    // ConditionCompiler writes the lone raw condition '0' as '(0)', which SQLBuilder never leaves out: the write keeps
    // its WHERE, which no row meets on MySQL, MariaDB and SQLite, and which Postgres refuses (no integer is a condition)
    public function test_a_where_of_0_is_sent_and_writes_no_row(): void
    {
        Author::table();
        $writes = ['update' => fn() => Author::where_raw('0')->update(['name' => 'x']), 'delete' => fn() => Author::where_raw('0')->delete()];

        foreach ($writes as $method => $write) {
            $statements = GoldenStatements::capture($write);

            $this->assert_string_contains_string(' WHERE (0)', $statements[0]['sql'] ?? '', $method);

            if ('pgsql' === $this->conn->protocol) {
                $this->assert_same(DatabaseException::class, $statements[1]['exception'] ?? null, $method);
            } else {
                $this->assert_same(0, $write(), $method);
            }
        }

        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], $this->names_by_id());
    }

    // The last guard before a statement: SQLBuilder writes a WHERE and an ORDER BY only when its text is truthy in PHP,
    // so a WHERE or an ORDER BY of the builder that compiled to '' (or an ORDER BY to '0') would be left out, and the
    // write would touch every row, or other rows; a blank one would fail at the database. The where and order methods
    // never record one (see test_blank_raw_sql_is_refused_at_the_call()); a state made by hand shows that the compiler
    // refuses it anyway.
    public function test_a_write_never_loses_a_where_or_an_order(): void
    {
        $compiler = new QueryCompiler(Author::table());
        $where = function (string $sql): QueryState {
            $state = new QueryState();
            $state->wheres = [['type' => 'raw', 'boolean' => 'AND', 'sql' => $sql, 'bindings' => []]];

            return $state;
        };
        $order = function (string $sql): QueryState {
            $state = new QueryState();
            $state->orders = [['type' => 'raw', 'sql' => $sql, 'bindings' => []]];
            $state->limit = 1;

            return $state;
        };

        foreach (['', ' '] as $sql) {
            $this->assert_same([ActiveRecordException::class, "The WHERE of update() on Author compiles to '$sql', which is no condition: the statement is not sent"], $this->thrown(fn() => $compiler->compile_update($where($sql), ['name' => 'x'])));
            $this->assert_same([ActiveRecordException::class, "The WHERE of delete() on Author compiles to '$sql', which is no condition: the statement is not sent"], $this->thrown(fn() => $compiler->compile_delete($where($sql))));
        }

        foreach (['', ' ', '0'] as $sql) {
            $this->assert_same([ActiveRecordException::class, "The ORDER BY of delete() on Author compiles to '$sql', which is no order: the statement is not sent"], $this->thrown(fn() => $compiler->compile_delete($order($sql))));
        }

        // a lone '0' is written '(0)' by ConditionCompiler, and kept
        $this->assert_same($this->sql('DELETE FROM `authors` WHERE (0)'), $compiler->compile_delete($where('0'))->to_s());
        $this->assert_same(4, Author::count());
    }

    // Spec §5.5: without a where, a delete deletes every row, but only from a form that says so, such as
    // new_query()->delete(): the static Author::delete() is PHP's Error (Model has an instance delete()), and
    // __callStatic() refuses a static update()
    public function test_a_write_without_a_where_writes_every_row(): void
    {
        $this->assert_same(4, Author::new_query()->update(['encrypted_password' => 'all']));
        $this->assert_same(4, Author::count(['conditions' => ['encrypted_password' => 'all']]));
        $this->assert_same(4, Author::new_query()->delete());
        $this->assert_same(0, Author::count());
    }

    // A where call that adds no condition is no condition for a read (spec §5.2), but a write whose where calls all
    // added none is refused: the conditions it was meant to write by turned out empty, where($filters) with no filter for
    // instance, and it would write every row. A write of every row calls no where (see above), and a where call that
    // added a condition beside an empty one is a WHERE as any other
    public function test_a_write_refuses_where_calls_that_all_added_no_condition(): void
    {
        $filters = [];
        Author::table();

        foreach ([
            'where([])' => fn() => Author::where($filters),
            'or_where([])' => fn() => Author::new_query()->or_where([]),
            'where_not([])' => fn() => Author::where_not([]),
            'where_null([])' => fn() => Author::where_null([]),
            'where([[closure]])' => fn() => Author::where([[fn(Query $q) => $q]]),
            'a closure that adds none' => fn() => Author::where(fn(Query $q) => null),
            'a closure of a closure that adds none' => fn() => Author::where(fn(Query $q) => $q->where(fn(Query $r) => $r->where([]))),
            'two empty calls' => fn() => Author::where([])->where_not(fn(Query $q) => $q),
            'a clone' => fn() => Author::where([])->clone(),
        ] as $name => $query) {
            foreach (['update' => fn() => $query()->update(['name' => 'x']), 'delete' => fn() => $query()->delete()] as $method => $write) {
                $this->assert_same(
                    [['exception' => ActiveRecordException::class, 'message' => $this->empty_where_refusal($method)]],
                    GoldenStatements::capture($write),
                    "$method after $name"
                );
            }

            // a read of the query is that of every row, as it was
            $this->assert_same(4, count($query()->get()), "get() after $name");
        }

        // an INSERT and an UPSERT take no where at all
        $this->assert_same([ActiveRecordException::class, 'insert() cannot use where, on Author: it takes no clause'], $this->thrown(fn() => Author::where([])->insert(['name' => 'x'])));
        $this->assert_same([ActiveRecordException::class, 'upsert() cannot use where, on Author: it takes no clause'], $this->thrown(fn() => Author::where([])->upsert([['author_id' => 1, 'name' => 'x']], 'author_id')));
        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], $this->names_by_id());

        // an empty call beside a condition: the condition is the WHERE
        $this->assert_same(1, Author::where([])->where('author_id', 1)->update(['name' => 'One']));
        $this->assert_same(1, Author::where(fn(Query $q) => $q->where([])->where('author_id', 2))->delete());
        $this->assert_same([1 => 'One', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], $this->names_by_id());

        // no where call at all: every row, as it says
        $this->assert_same(3, Author::new_query()->delete());
    }

    // A when() or an unless() that ran neither its callback nor a default is a filter that came out empty too, the optional
    // filter of the README: a write with no other where condition is refused, as where([]) is. One that ran its callback,
    // or its default, writes as the conditions it added say. Reads are unchanged, and a clone keeps what the query knows
    public function test_a_write_refuses_a_when_or_unless_that_skipped_its_callback(): void
    {
        $ids = [];
        Author::table();
        $by_ids = fn(Query $query, mixed $ids): Query => $query->where_key($ids);

        foreach ([
            'when($ids)' => [fn() => Author::when($ids, $by_ids), 4],
            'when(false)' => [fn() => Author::when(false, $by_ids), 4],
            'unless(true)' => [fn() => Author::unless(true, fn(Query $q) => $q->where('author_id', 1)), 4],
            'a closure given as the value' => [fn() => Author::when(fn(Query $q) => [], $by_ids), 4],
            'a clone' => [fn() => Author::when($ids, $by_ids)->clone(), 4],
            'an order and a limit beside it' => [fn() => Author::when($ids, $by_ids)->order_by('author_id')->limit(2), 2],
            'in a closure group' => [fn() => Author::where(fn(Query $q) => $q->when($ids, $by_ids)), 4],
        ] as $name => [$query, $read]) {
            foreach (['update' => fn() => $query()->update(['name' => 'x']), 'delete' => fn() => $query()->delete()] as $method => $write) {
                $this->assert_same([['exception' => ActiveRecordException::class, 'message' => $this->empty_where_refusal($method)]], GoldenStatements::capture($write), "$method after $name");
            }

            $this->assert_same($read, count($query()->get()), "get() after $name");
        }

        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], $this->names_by_id());

        // a condition beside it is the WHERE, and a callback or a default that ran writes by what it added
        $this->assert_same(1, Author::when($ids, $by_ids)->where('author_id', 1)->update(['name' => 'One']));
        $this->assert_same(1, Author::when([2], $by_ids)->update(['name' => 'Two']));
        $this->assert_same(1, Author::when(false, $by_ids, fn(Query $q) => $q->where_key(3))->update(['name' => 'Three']));
        $this->assert_same(1, Author::unless(false, fn(Query $q) => $q->where('author_id', 4))->update(['name' => 'Four']));
        $this->assert_same([1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four'], $this->names_by_id());
        $this->assert_same(1, Author::when($ids, $by_ids)->where('author_id', 1)->delete());
        $this->assert_same([2, 3, 4], array_keys($this->names_by_id()));
    }

    // The where of a write names its columns by the rule of every clause (spec §5.1): an alias and an attribute name
    // are the columns they stand for (#92, #144). So do the keys of the values (spec §5.5, see
    // test_the_keys_of_the_values_read_aliases()), and a DateTime is bound in the format of its column: a DATE as a date
    public function test_a_write_names_its_columns_as_the_reads_do(): void
    {
        $this->assert_same(1, Venue::where('marquee', 'Warner Theatre')->update(['state' => 'XX']));
        $this->assert_same('XX', Venue::find_by_name('Warner Theatre')->state);
        $this->assert_same(1, Venue::where('mycity', 'Washington')->where('state', 'XX')->delete());
        $this->assert_null(Venue::find_by_name('Warner Theatre'));

        OddColumn::table();
        $insert = GoldenStatements::capture(fn() => OddColumn::new_query()->insert(['space_name' => 's', 'dash_name' => 'a', 'some_day' => new DateTime('2026-01-02 14:30:00')]));
        $update = GoldenStatements::capture(fn() => OddColumn::where('space_name', 's')->update(['some_day' => new DateTime('2026-03-04 10:00:00'), 'dash_name' => 'd']));

        $this->assert_same([['sql' => $this->sql('INSERT INTO `odd_columns` (`space name`, `dash-name`, `some day`) VALUES (?, ?, ?)'), 'values' => ['s', 'a', '2026-01-02']]], $insert);
        $this->assert_same([['sql' => $this->sql('UPDATE `odd_columns` SET `some day`=?, `dash-name`=? WHERE `space name` = ?'), 'values' => ['2026-03-04', 'd', 's']]], $update);
        $this->assert_same('2026-03-04', OddColumn::find('first')->some_day->format('Y-m-d'));
    }

    // A key of the values names the real column of its attribute whatever the case it differs by, and the value is
    // prepared by that column: authors.some_Date, a DATE whose attribute is some_date, takes the date of a DateTime. MySQL,
    // MariaDB and SQLite match column names regardless of case, so Table maps no attribute name to it there, and the value
    // went as a date and time, which SQLite stored as it was given
    public function test_a_write_prepares_its_values_by_the_column_an_attribute_names_in_another_case(): void
    {
        Timestamps::freeze(self::FROZEN);
        Author::table();
        $noon = new DateTime('2026-01-02 12:00:00');
        $stored = fn(string $where): mixed => $this->conn->query($this->sql("SELECT `some_Date` FROM `authors` WHERE $where"))->fetchColumn();

        $this->assert_same(
            [['sql' => $this->sql('UPDATE `authors` SET `some_Date`=?, `updated_at`=? WHERE `author_id` = ?'), 'values' => ['2026-01-02', self::FROZEN, 2]]],
            GoldenStatements::capture(fn() => $this->assert_same(1, Author::where('author_id', 2)->update(['some_date' => $noon])))
        );
        $this->assert_same(
            [['sql' => $this->sql('INSERT INTO `authors` (`name`, `some_Date`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?)'), 'values' => ['Dated', '2026-01-02', self::FROZEN, self::FROZEN]]],
            GoldenStatements::capture(fn() => Author::new_query()->insert(['name' => 'Dated', 'some_date' => $noon]))
        );

        $this->assert_same('2026-01-02', $stored('`author_id` = 2'));
        $this->assert_same('2026-01-02', $stored("`name` = 'Dated'"));
        $this->assert_same(['2026-01-02', '2026-01-02'], [Author::find(2)->some_date->format('Y-m-d'), Author::find_by_name('Dated')->some_date->format('Y-m-d')]);
    }

    // Spec §5.5: the keys of the values of update() and insert() name their columns by the rule of the where, an alias of
    // $alias_attribute among them (marquee is Venue's name, mycity its city), which never shadows a column (#144). An
    // alias and its column in one hash name one column, the later value. A key that names no column, attribute or alias
    // is sent as written, and refused by the database, as before
    public function test_the_keys_of_the_values_read_aliases(): void
    {
        Venue::table();

        $this->assert_same(
            [['sql' => $this->sql('UPDATE `venues` SET `name`=? WHERE `name` = ?'), 'values' => ['Marquee', 'Warner Theatre']]],
            GoldenStatements::capture(fn() => $this->assert_same(1, Venue::where('marquee', 'Warner Theatre')->update(['marquee' => 'Marquee'])))
        );
        $this->assert_same(
            [['sql' => $this->sql('INSERT INTO `venues` (`name`, `city`, `address`) VALUES (?, ?, ?), (?, ?, ?)'), 'values' => ['Hall', 'Austin', '1 Main St', 'Room', 'Dallas', '2 Main St']]],
            GoldenStatements::capture(fn() => Venue::new_query()->insert([['marquee' => 'Hall', 'mycity' => 'Austin', 'address' => '1 Main St'], ['address' => '2 Main St', 'name' => 'Room', 'city' => 'Dallas']]))
        );

        $this->assert_same('Washington', Venue::find_by_name('Marquee')->city);
        $this->assert_same(['Austin', 'Dallas'], [Venue::find_by_name('Hall')->city, Venue::find_by_name('Room')->city]);

        // an alias and its column in one hash: one column, the later value
        $this->assert_same(1, Venue::where('marquee', 'Hall')->update(['marquee' => 'First', 'name' => 'Second']));
        $this->assert_null(Venue::find_by_name('First'));
        $this->assert_same('Austin', Venue::find_by_name('Second')->city);

        // no column, attribute or alias: as written, to the database
        foreach ([fn() => Venue::where('marquee', 'Room')->update(['no_such_column' => 'x']), fn() => Venue::new_query()->insert(['no_such_column' => 'x'])] as $write) {
            $statements = GoldenStatements::capture($write);

            $this->assert_string_contains_string($this->conn->quote_name('no_such_column'), $statements[0]['sql'] ?? '');
            $this->assert_same(DatabaseException::class, $statements[1]['exception'] ?? null);
        }

        $this->assert_same('Dallas', Venue::find_by_name('Room')->city);
    }

    // -- the order and the limit of a write

    // Spec §5.5: a limited UPDATE or DELETE writes the first rows of its order, on every adapter: MySQL, MariaDB and
    // SQLite take ORDER BY and LIMIT on them (the SQLite of the tests is built with SQLITE_ENABLE_UPDATE_DELETE_LIMIT),
    // and Postgres picks the rows by primary key in a subquery (#92), also without a WHERE
    public function test_a_limited_write_writes_the_first_rows_of_its_order(): void
    {
        $this->assert_same(2, Author::where('author_id', '>', 1)->order_by('author_id', 'desc')->limit(2)->update(['name' => 'Last']));
        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Last', 4 => 'Last'], $this->names_by_id());

        $this->assert_same(1, Author::where('name', 'Last')->order_by_desc('author_id')->limit(1)->delete());
        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Last'], $this->names_by_id());

        // George W. Bush, the first by name
        $this->assert_same(1, Author::order_by('name')->limit(1)->delete());
        $this->assert_same([1 => 'Tito', 3 => 'Last'], $this->names_by_id());

        // one row, at random, and a limit of 0 writes none (#34)
        $this->assert_same(1, Author::in_random_order()->limit(1)->delete());
        $this->assert_same(1, Author::count());
        $this->assert_same(0, Author::new_query()->limit(0)->update(['name' => 'None']));
        $this->assert_same(0, Author::new_query()->limit(0)->delete());
        $this->assert_same(1, Author::count());
    }

    // The values of an UPDATE are bound in the order of its markers: the SET, then the WHERE, then the ORDER BY. On
    // Postgres the WHERE and the ORDER BY are those of the subquery that picks the rows, in the same order
    public function test_a_write_binds_its_set_then_its_where_then_its_order(): void
    {
        Timestamps::freeze(self::FROZEN);
        Author::table();
        $first = 'CASE WHEN author_id = ? THEN 0 ELSE 1 END';

        $update = GoldenStatements::capture(fn() => Author::where('author_id', '>', 1)->order_by_raw($first, [3])->limit(1)->update(['name' => 'Third']));
        $delete = GoldenStatements::capture(fn() => Author::where('author_id', '>', 1)->order_by_raw($first, [4])->limit(1)->delete());
        $last_sql = Author::table()->last_sql;

        if ('pgsql' === $this->conn->protocol) {
            $rows = "\"author_id\" IN (SELECT \"author_id\" FROM \"authors\" WHERE \"author_id\" > ? ORDER BY $first LIMIT 1)";
            $update_sql = "UPDATE \"authors\" SET \"name\"=?, \"updated_at\"=? WHERE $rows";
            $delete_sql = "DELETE FROM \"authors\" WHERE $rows";
        } else {
            $update_sql = "UPDATE `authors` SET `name`=?, `updated_at`=? WHERE `author_id` > ? ORDER BY $first LIMIT 1";
            $delete_sql = "DELETE FROM `authors` WHERE `author_id` > ? ORDER BY $first LIMIT 1";
        }

        $this->assert_same([['sql' => $update_sql, 'values' => ['Third', self::FROZEN, 1, 3]]], $update);
        $this->assert_same([['sql' => $delete_sql, 'values' => [1, 4]]], $delete);
        $this->assert_same($delete_sql, $last_sql);
        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Third'], $this->names_by_id());
    }

    // An order without a limit, as the legacy writes send it. Postgres, which has no ORDER BY on UPDATE and DELETE,
    // leaves it out, and its values with it. MySQL and MariaDB send it, its values after the WHERE's. SQLite sends it
    // too, and refuses it ("ORDER BY without LIMIT"): no row is written there, as with the legacy options
    public function test_an_order_without_a_limit_is_sent_as_the_legacy_writes_send_it(): void
    {
        Author::table();
        $order = 'CASE WHEN author_id = ? THEN 0 ELSE 1 END';
        $statements = GoldenStatements::capture(fn() => Author::where('author_id', '>', 2)->order_by_raw($order, [3])->delete());

        if ('pgsql' === $this->conn->protocol) {
            $this->assert_same([['sql' => 'DELETE FROM "authors" WHERE "author_id" > ?', 'values' => [2]]], $statements);
            $this->assert_same([1 => 'Tito', 2 => 'George W. Bush'], $this->names_by_id());

            return;
        }

        $this->assert_same(['sql' => "DELETE FROM `authors` WHERE `author_id` > ? ORDER BY $order", 'values' => [2, 3]], $statements[0]);

        if ('sqlite' === $this->conn->protocol) {
            $this->assert_same(DatabaseException::class, $statements[1]['exception'] ?? null);
            $this->assert_string_contains_string('ORDER BY without LIMIT on DELETE', $statements[1]['message'] ?? '');
            $this->assert_same(
                [DatabaseException::class, $statements[1]['message'] ?? ''],
                $this->thrown(fn() => Author::delete_all(['conditions' => ['author_id > ?', 2], 'order' => 'author_id']))
            );
            $this->assert_same(4, Author::count());

            return;
        }

        $this->assert_same(1, count($statements));
        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush'], $this->names_by_id());
    }

    // -- the clauses a write refuses

    /**
     * A query with a clause that an UPDATE and a DELETE cannot use, and the name the refusal gives it.
     *
     * @return array<string, array{Closure(): Query<Author>, string}>
     */
    public static function unwritable_clauses(): array
    {
        $on = ['books.author_id', '=', 'authors.author_id'];

        return [
            'select' => [fn() => Author::select('name'), 'select'],
            'add_select' => [fn() => Author::add_select('name'), 'select'],
            'select_raw' => [fn() => Author::select_raw('1 AS one'), 'select'],
            'distinct' => [fn() => Author::distinct(), 'distinct'],
            'from' => [fn() => Author::from('authors', 'a'), 'from'],
            'from_raw' => [fn() => Author::from_raw('authors a'), 'from_raw'],
            'joins' => [fn() => Author::joins('books'), 'joins'],
            'joins of a fragment' => [fn() => Author::joins('INNER JOIN books ON(books.author_id = authors.author_id)'), 'joins'],
            'left_joins' => [fn() => Author::left_joins('books'), 'joins'],
            'join' => [fn() => Author::join('books', ...$on), 'joins'],
            'left_join' => [fn() => Author::left_join('books', ...$on), 'joins'],
            'right_join' => [fn() => Author::right_join('books', ...$on), 'joins'],
            'cross_join' => [fn() => Author::cross_join('books'), 'joins'],
            'group_by' => [fn() => Author::group_by('name'), 'group_by'],
            'group_by_raw' => [fn() => Author::group_by_raw('name'), 'group_by'],
            'having' => [fn() => Author::having('name', 'x'), 'having'],
            'having_raw' => [fn() => Author::having_raw('COUNT(*) > ?', [1]), 'having'],
            'offset' => [fn() => Author::offset(1), 'offset'],
            'offset(0)' => [fn() => Author::offset(0), 'offset'],
            'skip' => [fn() => Author::skip(2), 'offset'],
            'for_page' => [fn() => Author::for_page(1, 2), 'offset'],
            'with' => [fn() => Author::with('books'), 'with'],
            'readonly' => [fn() => Author::new_query()->readonly(), 'readonly'],
        ];
    }

    /**
     * Spec §5.5: a write that carries a clause it cannot use throws instead of ignoring it, at the call, before any
     * statement, and whatever where, order and limit the query has besides.
     *
     * @param Closure(): Query<Author> $query
     */
    #[DataProvider('unwritable_clauses')]
    public function test_update_and_delete_refuse_a_clause_they_cannot_use(Closure $query, string $clause): void
    {
        // built before the captures, which would otherwise hold the schema reads of the tables it loads
        $query = $query()->where('author_id', 1)->order_by('name')->limit(1);

        foreach ([
            'update' => fn() => $query->update(['name' => 'x']),
            'delete' => fn() => $query->delete(),
        ] as $method => $write) {
            $this->assert_same(
                [['exception' => ActiveRecordException::class, 'message' => "$method() cannot use $clause, on Author: " . self::TAKES]],
                GoldenStatements::capture($write)
            );
        }

        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], $this->names_by_id());
    }

    // The refusal names every clause the write cannot use, once, in the order of the query's state; a clause undone
    // (readonly(false), distinct(false), a select() of nothing) is no clause
    public function test_the_refusal_names_every_clause_the_write_cannot_use(): void
    {
        $this->assert_same(
            [ActiveRecordException::class, 'delete() cannot use distinct, joins, group_by or offset, on Author: ' . self::TAKES],
            $this->thrown(fn() => Author::joins('books')->join('books b', 'b.author_id', '=', 'authors.author_id')->offset(1)->group_by('name')->distinct()->delete())
        );
        $this->assert_same(
            [ActiveRecordException::class, 'update() cannot use from or with, on Author: ' . self::TAKES],
            $this->thrown(fn() => Author::from('authors', 'a')->with('books')->update(['name' => 'x']))
        );

        $this->assert_same(1, Author::where('author_id', 1)->readonly()->readonly(false)->distinct()->distinct(false)->select('name')->select()->delete());
    }

    // An INSERT and an UPSERT write the rows they are given: no clause of the query has a meaning for them, a where
    // least of all, so each is refused (spec §5.5) where it used to be ignored
    public function test_insert_and_upsert_refuse_every_clause(): void
    {
        $row = ['author_id' => 1, 'name' => 'Upserted'];

        // built before the captures, which would otherwise hold the schema reads of the tables they load
        foreach ([
            'where' => Author::where('author_id', 1),
            'order_by or limit' => Author::order_by('name')->limit(1),
            'joins' => Author::joins('books'),
            'readonly' => Author::new_query()->readonly(),
        ] as $clause => $query) {
            $this->assert_same(
                [['exception' => ActiveRecordException::class, 'message' => "insert() cannot use $clause, on Author: it takes no clause"]],
                GoldenStatements::capture(fn() => $query->insert(['name' => 'Inserted']))
            );
            $this->assert_same(
                [['exception' => ActiveRecordException::class, 'message' => "upsert() cannot use $clause, on Author: it takes no clause"]],
                GoldenStatements::capture(fn() => $query->upsert([$row], 'author_id'))
            );
            // and the INSERT of one row that Table's inserts send, which never hold a clause
            $this->assert_same(
                [['exception' => ActiveRecordException::class, 'message' => "insert() cannot use $clause, on Author: it takes no clause"]],
                GoldenStatements::capture(fn() => $query->execute_insert(['name' => 'Inserted']))
            );
        }

        // legacy options are refused too (an INSERT would ignore their conditions), unless there are none
        $legacy = Query::from_options(Author::table(), ['conditions' => ['author_id' => 1]]);
        $this->assert_same([ActiveRecordException::class, 'insert() cannot use legacy options, on Author: it takes no clause'], $this->thrown(fn() => $legacy->execute_insert(['name' => 'Inserted'])));
        $this->assert_same([ActiveRecordException::class, 'upsert() cannot use legacy options, on Author: it takes no clause'], $this->thrown(fn() => $legacy->upsert([$row], 'author_id')));
        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], $this->names_by_id());
        $this->assert_same(1, Query::from_options(Author::table(), [])->execute_insert(['name' => 'Inserted'])->rowCount());

        // the upsert of a query without clauses, as Model::upsert() makes it, runs as it always did
        $this->assert_same(1, Author::new_query()->upsert([['author_id' => 9, 'name' => 'Upserted']], 'author_id'));
        $this->assert_same('Upserted', Author::find(9)->name);
    }

    // -- raw SQL that a clause would lose (spec §5.7)

    // SQLBuilder leaves out a WHERE, a HAVING, an ORDER BY and a GROUP BY whose text is empty, so empty raw SQL would be
    // lost: where_raw('')->delete() would delete every row. Blank text is refused at the call, in every raw method of
    // a condition, an order and a group, and in a closure group too, before the query changes
    public function test_blank_raw_sql_is_refused_at_the_call(): void
    {
        foreach ([
            'where_raw' => [fn(Query $q) => $q->where_raw(''), ''],
            'or_where_raw' => [fn(Query $q) => $q->or_where_raw("  \n"), "  \n"],
            'having_raw' => [fn(Query $q) => $q->having_raw('', [1]), ''],
            'or_having_raw' => [fn(Query $q) => $q->or_having_raw(' '), ' '],
            'order_by_raw' => [fn(Query $q) => $q->order_by_raw(''), ''],
            'group_by_raw' => [fn(Query $q) => $q->group_by_raw("\t"), "\t"],
        ] as $method => [$call, $sql]) {
            $query = Author::where('author_id', 1);
            $before = clone $query->state();

            $this->assert_same([ActiveRecordException::class, "Empty SQL: '$sql' in $method() on Author"], $this->thrown(fn() => $call($query)), $method);
            $this->assert_equals($before, $query->state(), "$method leaves the query as it was");
        }

        $this->assert_same([ActiveRecordException::class, "Empty SQL: '' in where_raw() on Author"], $this->thrown(fn() => Author::where(fn(Query $q) => $q->where_raw(''))));
        $this->assert_same(
            [['exception' => ActiveRecordException::class, 'message' => "Empty SQL: '' in where_raw() on Author"]],
            GoldenStatements::capture(fn() => Author::where_raw('')->delete())
        );
        $this->assert_same(4, Author::count());
    }

    // An ORDER BY and a GROUP BY read 0 as the position of a column, which no statement has (positions start at 1): the
    // databases refuse it, but SQLBuilder leaves out a lone '0', which turns the error into another result, so it is
    // refused at the call. A WHERE and a HAVING keep their '0', as '(0)' (see test_a_where_of_0_is_sent_and_writes_no_row())
    public function test_order_by_raw_and_group_by_raw_refuse_the_position_0(): void
    {
        foreach (['order_by_raw', 'group_by_raw'] as $method) {
            foreach (['0', ' 0 '] as $sql) {
                $this->assert_same(
                    [ActiveRecordException::class, "SQL '$sql' in $method() on Author names the column at position 0, which no statement has: positions start at 1"],
                    $this->thrown(fn() => Author::new_query()->$method($sql))
                );
            }
        }

        // a position that a column has is SQL as any other
        $this->assert_sql_has('ORDER BY 1', Author::select('name')->order_by_raw('1')->to_sql());

        $this->assert_sql_has('WHERE (0)', Author::where_raw('0')->to_sql());
        $this->assert_sql_has('HAVING (0)', Author::group_by('name')->having_raw('0')->to_sql());
    }

    // The refusals are the builder's: the legacy options are read as they always were, so an empty condition and an
    // order '0' are still left out by SQLBuilder there
    public function test_the_legacy_options_still_leave_out_what_sqlbuilder_leaves_out(): void
    {
        $this->assert_same(4, count(Author::all(['conditions' => ''])));
        $this->assert_same(4, count(Author::all(['order' => '0'])));
        $this->assert_same('SELECT * FROM ' . $this->conn->quote_name('authors'), Author::table()->last_sql);
    }

    // -- update()

    // Empty values are refused as update_all() refuses them, and so is a list, before any statement. A value is one
    // value of a column: never an array, an enum (not supported) or an object that is neither a DateTime nor a
    // Stringable, which is bound as its text
    public function test_update_refuses_values_it_cannot_write(): void
    {
        Author::table();
        $refused = fn(string $message): array => [['exception' => ActiveRecordException::class, 'message' => $message]];

        $this->assert_same($refused('Updating requires a hash or string.'), GoldenStatements::capture(fn() => Author::where('author_id', 1)->update([])));
        $this->assert_same($refused('Updating requires a hash or string.'), GoldenStatements::capture(fn() => Author::where('author_id', 1)->update(['x'])));
        $this->assert_same(
            $refused("update() takes a scalar, null, a DateTime or a Stringable as a value, not array for 'name', on Author"),
            GoldenStatements::capture(fn() => Author::where('author_id', 1)->update(['parent_author_id' => 2, 'name' => ['x']]))
        );
        $this->assert_same(
            $refused("update() takes a scalar, null, a DateTime or a Stringable as a value, not stdClass for 'name', on Author"),
            GoldenStatements::capture(fn() => Author::where('author_id', 1)->update(['name' => new stdClass()]))
        );
        $this->assert_same(
            $refused("update() does not support an enum as a value: QueryWriteSuit::Hearts for 'name', on Author; give the scalar it stands for"),
            GoldenStatements::capture(fn() => Author::where('author_id', 1)->update(['name' => QueryWriteSuit::Hearts]))
        );
        $this->assert_same(
            $refused("update() does not support an enum as a value: QueryWritePureSuit::Spades for 'name', on Author; give the scalar it stands for"),
            GoldenStatements::capture(fn() => Author::where('author_id', 1)->update(['name' => QueryWritePureSuit::Spades]))
        );
        $this->assert_same([1 => 'Tito', 2 => 'George W. Bush', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], $this->names_by_id());
    }

    // A Stringable value is bound as its text, as update_all() binds it; a DateTime, ActiveRecord's own included (which
    // is a Stringable too), is bound in the format of its column, never as its text
    public function test_a_stringable_value_is_written_as_its_text(): void
    {
        Timestamps::freeze(self::FROZEN);
        Author::table();
        $text = new class implements Stringable {
            public function __toString(): string
            {
                return 'Texted';
            }
        };

        $this->assert_same(
            [['sql' => $this->sql('UPDATE `authors` SET `name`=?, `some_Date`=?, `updated_at`=? WHERE `author_id` = ?'), 'values' => ['Texted', '2026-01-02', self::FROZEN, 1]]],
            GoldenStatements::capture(fn() => Author::where('author_id', 1)->update(['name' => $text, 'some_Date' => new ActiveRecord\DateTime('2026-01-02 14:30:00')]))
        );
        $this->assert_true(Author::new_query()->insert([['name' => $text, 'parent_author_id' => 7], ['name' => 'Plain', 'parent_author_id' => 8]]));

        $this->assert_same(['Texted', 'Texted', 'Plain'], [Author::find(1)->name, Author::find_by_parent_author_id(7)->name, Author::find_by_parent_author_id(8)->name]);
        $this->assert_same('2026-01-02', Author::find(1)->some_date->format('Y-m-d'));
    }

    // Spec §5.6: an UPDATE sets updated_at to now unless its values give one (null included) or the class is suspended,
    // and never created_at; a table whose timestamp columns have other names gets them by their attribute names (#92)
    public function test_update_sets_updated_at_unless_it_is_given_or_suspended(): void
    {
        Timestamps::freeze(self::FROZEN);
        Author::table();

        $this->assert_same(
            [['sql' => $this->sql('UPDATE `authors` SET `name`=?, `updated_at`=? WHERE `author_id` = ?'), 'values' => ['One', self::FROZEN, 1]]],
            GoldenStatements::capture(fn() => Author::where('author_id', 1)->update(['name' => 'One']))
        );
        Author::where('author_id', 2)->update(['name' => 'Two', 'updated_at' => '2020-01-01 00:00:00']);
        Author::without_timestamps(fn() => Author::where('author_id', 3)->update(['name' => 'Three']));

        $this->assert_same(['created_at' => null, 'updated_at' => self::FROZEN], $this->stamps('One'));
        $this->assert_same(['created_at' => null, 'updated_at' => '2020-01-01 00:00:00'], $this->stamps('Two'));
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps('Three'));

        OddTimestamp::new_query()->insert(['name' => 'odd']);
        Timestamps::freeze('2026-10-22 08:00:00');
        $this->assert_same(1, OddTimestamp::where('name', 'odd')->update(['name' => 'odder']));
        $odd = OddTimestamp::find('first');
        $this->assert_same([self::FROZEN, '2026-10-22 08:00:00'], [$odd->created_at->format('Y-m-d H:i:s'), $odd->updated_at->format('Y-m-d H:i:s')]);
    }

    // update() returns the rows the driver counts (PDOStatement::rowCount()): those the WHERE matched on Postgres and
    // SQLite, those whose values changed on MySQL and MariaDB. updated_at moves to now, so there a row matched counts,
    // unless it already held every value at that second; without timestamps, a row written with its own values does not
    public function test_update_returns_the_rows_the_driver_counts(): void
    {
        $changed_only = $this->conn instanceof ActiveRecord\MysqlAdapter;
        Timestamps::freeze(self::FROZEN);

        $this->assert_same(1, Author::where('author_id', 1)->update(['name' => 'Tito']));
        $this->assert_same($changed_only ? 0 : 1, Author::where('author_id', 1)->update(['name' => 'Tito']));
        $this->assert_same($changed_only ? 0 : 1, Author::without_timestamps(fn() => Author::where('author_id', 2)->update(['name' => 'George W. Bush'])));
        $this->assert_same(2, Author::where('parent_author_id', 2)->update(['name' => 'Changed']));
    }

    // -- insert()

    // One hash is one row; a list of hashes is one INSERT of several VALUES, every row stamped with the one time of the
    // call (spec §5.6). The statement is left in Table::$last_sql
    public function test_insert_sends_one_statement_of_its_rows(): void
    {
        Timestamps::freeze(self::FROZEN);
        Author::table();

        $this->assert_same(
            [['sql' => $this->sql('INSERT INTO `authors` (`name`, `created_at`, `updated_at`) VALUES (?, ?, ?)'), 'values' => ['One', self::FROZEN, self::FROZEN]]],
            GoldenStatements::capture(fn() => $this->assert_true(Author::new_query()->insert(['name' => 'One'])))
        );

        $sql = $this->sql('INSERT INTO `authors` (`name`, `parent_author_id`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?), (?, ?, ?, ?)');
        $this->assert_same(
            [['sql' => $sql, 'values' => ['Two', 1, self::FROZEN, self::FROZEN, 'Three', 2, self::FROZEN, self::FROZEN]]],
            GoldenStatements::capture(fn() => Author::new_query()->insert([['name' => 'Two', 'parent_author_id' => 1], ['parent_author_id' => 2, 'name' => 'Three']]))
        );
        $this->assert_same($sql, Author::table()->last_sql);

        $this->assert_same([1, 2], [Author::find_by_name('Two')->parent_author_id, Author::find_by_name('Three')->parent_author_id]);
        $this->assert_same(['created_at' => self::FROZEN, 'updated_at' => self::FROZEN], $this->stamps('Three'));
        $this->assert_same(7, Author::count());
    }

    // The rows are read before any statement (SQLBuilder::insert_rows() reads none): there is at least one; each is a
    // hash of column => value with at least one column, whose keys every row shares (in any order); a value is a
    // scalar, null, a DateTime or a Stringable, never an array, an enum (not supported) or another object
    public function test_insert_refuses_rows_it_cannot_write(): void
    {
        Author::table();

        foreach ([
            'insert() needs at least one row, on Author' => [],
            'insert() needs at least one column in each row, on Author' => [['name' => 'A'], []],
            'insert() takes each row as a hash of column => value, not string, on Author' => ['A', 'B'],
            'insert() takes each row as a hash of column => value, not an array with the integer key 0, on Author' => [['A']],
            'insert() takes each row as a hash of column => value, not an array with the integer key 1, on Author' => ['name' => 'A', 1 => 'B'],
            'insert() needs every row to have the same keys, on Author' => [['name' => 'A'], ['name' => 'B', 'parent_author_id' => 1]],
            "insert() takes a scalar, null, a DateTime or a Stringable as a value, not array for 'name', on Author" => [['name' => ['A']]],
            "insert() takes a scalar, null, a DateTime or a Stringable as a value, not stdClass for 'name', on Author" => ['name' => new stdClass()],
            "insert() does not support an enum as a value: QueryWriteSuit::Hearts for 'name', on Author; give the scalar it stands for" => [['name' => QueryWriteSuit::Hearts]],
            "insert() does not support an enum as a value: QueryWritePureSuit::Spades for 'name', on Author; give the scalar it stands for" => ['name' => QueryWritePureSuit::Spades],
        ] as $message => $values) {
            $this->assert_same([['exception' => ActiveRecordException::class, 'message' => $message]], GoldenStatements::capture(fn() => Author::new_query()->insert($values)));
        }

        // a row that gives a timestamp does not share its keys with one that leaves it to the clock
        $this->assert_same(
            [ActiveRecordException::class, 'insert() needs every row to have the same keys, on Author'],
            $this->thrown(fn() => Author::new_query()->insert([['name' => 'A'], ['name' => 'B', 'updated_at' => self::FROZEN]]))
        );
        $this->assert_same(4, Author::count());
    }

    // Spec §5.6: one time for the whole call. This Table moves the frozen clock a second on at every connection() call,
    // which the stamping of a row makes before it would read the clock for itself: a row that did read it would get a
    // later time than the row before. Chunked, the rows of the later statements must not read a later one either
    public function test_the_rows_of_an_insert_all_take_the_one_time_of_the_call(): void
    {
        $table = new class (Author::class) extends Table {
            public bool $ticking = false;

            public function connection(): ActiveRecord\Connection
            {
                if ($this->ticking) {
                    Timestamps::freeze(date('Y-m-d H:i:s', (int) strtotime(Timestamps::now()) + 1));
                }

                return parent::connection();
            }
        };
        $class = get_class($this->conn);
        $previous = $class::$MAX_BIND_PARAMS;

        try {
            // five rows in one statement, then in three (two rows of four columns each)
            foreach ([['One', $previous], ['Chunked', 8]] as [$prefix, $max_bind_params]) {
                $class::$MAX_BIND_PARAMS = $max_bind_params;
                $names = array_map(fn(int $i): string => "$prefix $i", range(1, 5));

                Timestamps::freeze(self::FROZEN);
                $table->ticking = true;
                Query::for_table($table)->insert(array_map(fn(string $name): array => ['name' => $name, 'parent_author_id' => 1], $names));
                $table->ticking = false;

                // the probe was live: the clock moved on while the rows were stamped
                $this->assert_not_same(self::FROZEN, Timestamps::now());

                $stamps = array_map($this->stamps(...), $names);
                $this->assert_not_null($stamps[0]['created_at']);
                $this->assert_same($stamps[0]['created_at'], $stamps[0]['updated_at']);
                $this->assert_same(array_fill(0, 5, $stamps[0]), $stamps);
            }
        } finally {
            $class::$MAX_BIND_PARAMS = $previous;
        }
    }

    // Model::without_timestamps() suspends the timestamps of the class it is called on, and of no other: not of a
    // subclass, nor of a parent class (spec §4.1)
    public function test_without_timestamps_suspends_the_writes_of_its_class_only(): void
    {
        Timestamps::freeze(self::FROZEN);

        Author::without_timestamps(fn() => Author::new_query()->insert(['name' => 'Quiet']));
        ShadowingAuthor::without_timestamps(fn() => Author::new_query()->insert(['name' => 'Loud']));
        Author::without_timestamps(fn() => ShadowingAuthor::new_query()->insert(['name' => 'Shadow']));
        Author::without_timestamps(fn() => Author::where('author_id', 1)->update(['name' => 'QuietUpdate']));
        ShadowingAuthor::without_timestamps(fn() => Author::where('author_id', 2)->update(['name' => 'LoudUpdate']));

        $stamped = ['created_at' => self::FROZEN, 'updated_at' => self::FROZEN];
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps('Quiet'));
        $this->assert_same($stamped, $this->stamps('Loud'));
        $this->assert_same($stamped, $this->stamps('Shadow'));
        $this->assert_same(['created_at' => null, 'updated_at' => null], $this->stamps('QuietUpdate'));
        $this->assert_same(['created_at' => null, 'updated_at' => self::FROZEN], $this->stamps('LoudUpdate'));
    }

    // The rows go in statements of at most Connection::$MAX_BIND_PARAMS values (the logic of upsert(), shared): four
    // columns and a limit of 8 make statements of two rows
    public function test_insert_splits_its_rows_by_the_bind_limit(): void
    {
        Timestamps::freeze(self::FROZEN);
        Author::table();
        $class = get_class($this->conn);
        $previous = $class::$MAX_BIND_PARAMS;
        $class::$MAX_BIND_PARAMS = 8;

        try {
            $rows = array_map(fn(int $i): array => ['name' => "Chunk $i", 'parent_author_id' => $i], range(1, 5));
            $statements = GoldenStatements::capture(fn() => $this->assert_true(Author::new_query()->insert($rows)));
        } finally {
            $class::$MAX_BIND_PARAMS = $previous;
        }

        $this->assert_same([8, 8, 4], array_map(fn(array $statement): int => count($statement['values']), $statements));
        $this->assert_same(['Chunk 1', 'Chunk 2', 'Chunk 3', 'Chunk 4', 'Chunk 5'], array_map(fn(Author $author): string => $author->name, Author::all(['conditions' => "name LIKE 'Chunk %'", 'order' => 'name'])));

        // a row of more columns than the adapter binds in one statement is refused before any statement
        $class::$MAX_BIND_PARAMS = 3;

        try {
            $this->assert_same(
                [['exception' => ActiveRecordException::class, 'message' => "insert(): a row has more columns (4) than the adapter's bind-parameter limit (3)."]],
                GoldenStatements::capture(fn() => Author::new_query()->insert(['name' => 'Wide', 'parent_author_id' => 1]))
            );
        } finally {
            $class::$MAX_BIND_PARAMS = $previous;
        }
    }

    // Several statements run in one transaction, so a failing one undoes the rows of those before it. In a
    // transaction that is open already they run in it, with no savepoint of their own: the caller's rollback undoes
    // them
    public function test_the_statements_of_an_insert_are_one_transaction(): void
    {
        Author::table();
        $class = get_class($this->conn);
        $previous = $class::$MAX_BIND_PARAMS;
        // name, created_at and updated_at: two rows a statement
        $class::$MAX_BIND_PARAMS = 6;

        try {
            // the third row breaks authors.name NOT NULL, in the second statement
            $failing = GoldenStatements::capture(fn() => Author::new_query()->insert([['name' => 'Undone'], ['name' => 'Undone'], ['name' => null]]));
            $this->assert_same(3, count($failing));
            $this->assert_same(DatabaseException::class, $failing[2]['exception'] ?? null);
            $this->assert_same(4, Author::count());
            $this->assert_false($this->conn->inTransaction());

            $this->conn->transaction();

            try {
                $joined = GoldenStatements::capture(fn() => Author::new_query()->insert([['name' => 'Joined'], ['name' => 'Joined'], ['name' => 'Joined']]));
                $this->assert_same(7, Author::count());
            } finally {
                $this->conn->rollback();
            }
        } finally {
            $class::$MAX_BIND_PARAMS = $previous;
        }

        $this->assert_same([2, 1], array_map(fn(array $statement): int => intdiv(count($statement['values']), 3), $joined));
        $this->assert_same(4, Author::count());
    }

    // -- where the writes run

    // update(), delete() and insert() run on the connection of the model's Table, as upsert() does: never on the one a
    // subclass's connection() override returns, which the legacy update_all() and delete_all() take
    public function test_the_writes_run_on_the_connection_of_the_table(): void
    {
        RoutedAuthor::table();
        $routed = ActiveRecord\Connection::instance(ActiveRecord\Config::instance()->get_default_connection());
        $routed->last_query = 'untouched';
        RoutedAuthor::$route = $routed;

        try {
            $this->assert_same(1, RoutedAuthor::where('author_id', 1)->update(['name' => 'Tabled']));
            $this->assert_sql_has('UPDATE authors SET name=?', (string) $this->conn->last_query);
            $this->assert_same(1, RoutedAuthor::where('author_id', 2)->delete());
            $this->assert_sql_has('DELETE FROM authors WHERE author_id = ?', (string) $this->conn->last_query);
            $this->assert_true(RoutedAuthor::new_query()->insert(['name' => 'Inserted']));
            $this->assert_sql_has('INSERT INTO authors', (string) $this->conn->last_query);
            $on_routed = $routed->last_query;
        } finally {
            RoutedAuthor::$route = null;
            $routed->close();
        }

        $this->assert_same('untouched', $on_routed);
        $this->assert_same([1 => 'Tabled', 3 => 'Bill Clinton', 4 => 'Uncle Bob'], array_slice($this->names_by_id(), 0, 3, true));
        $this->assert_same(1, Author::count(['conditions' => ['name' => 'Inserted']]));
    }

    // Spec §4.1: the writes of the whole table are not forwarded statically. Author::insert() reaches __callStatic()
    // (Model's own insert() is private), which answers it as it answers any name it does not forward; a static
    // update() is refused, and a static delete() is PHP's own Error (Model has an instance delete())
    public function test_the_writes_are_not_forwarded_statically(): void
    {
        foreach (['insert', 'update', 'delete', 'upsert'] as $name) {
            $this->assert_false(in_array($name, Query::FORWARDABLE, true), "$name is not forwarded");
        }

        Author::table();
        $this->assert_same(
            [['exception' => ActiveRecordException::class, 'message' => 'Call to undefined method: insert']],
            GoldenStatements::capture(fn() => Author::insert(['name' => 'x']))
        );
        $this->assert_same(ActiveRecordException::class, $this->thrown(fn() => Author::update(['name' => 'x']))[0] ?? null);
        $this->assert_same(Error::class, $this->thrown(fn() => Author::delete())[0] ?? null);
        $this->assert_same(4, Author::count());
    }
}
