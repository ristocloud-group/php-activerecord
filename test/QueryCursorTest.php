<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\Config;
use ActiveRecord\Connection;
use ActiveRecord\ConnectionBusy;
use ActiveRecord\DatabaseException;

/**
 * Query::cursor(): the models of one SELECT, hydrated one row at a time (spec §4.4, buffered; §4.5, stream).
 *
 * Fixture data: authors 1 Tito, 2 George W. Bush, 3 Bill Clinton, 4 Uncle Bob; dated_counts (2026-01-01, 1 hit) and
 * (2026-01-02, 2 hits).
 */
class QueryCursorTest extends DatabaseTest
{
    public function test_cursor_yields_same_models_as_get()
    {
        $got = Author::order_by('author_id')->get();
        $models = iterator_to_array(Author::order_by('author_id')->cursor());

        $this->assert_same([0, 1, 2, 3], array_keys($models));
        $this->assert_same(
            array_map(fn($m) => $m->attributes(), $got),
            array_map(fn($m) => $m->attributes(), $models)
        );
    }

    public function test_cursor_runs_no_sql_before_iteration()
    {
        $before = Author::table()->last_sql;
        $c = Author::where('author_id', 2)->cursor();

        $this->assert_same($before, Author::table()->last_sql);
        $this->assert_same(2, $c->current()->author_id);
    }

    public function test_cursor_with_eager_load_throws()
    {
        $this->expectException(ActiveRecordException::class);
        $this->expectExceptionMessage('cursor() cannot eager load relationships (with()); use lazy() instead');

        Author::with('books')->cursor();
    }

    public function test_cursor_with_legacy_include_throws()
    {
        $this->expectException(ActiveRecordException::class);
        $this->expectExceptionMessage('cursor() cannot eager load');

        ActiveRecord\Query::from_options(Author::table(), ['include' => 'books'])->cursor();
    }

    public function test_buffered_cursor_allows_other_queries()
    {
        $names = [];

        foreach (Author::order_by('author_id')->cursor() as $author) {
            $names[] = Author::find(1)->name;
        }

        $this->assert_same(4, count($names));
    }

    public function test_cursor_readonly()
    {
        $count = 0;

        foreach (Author::new_query()->readonly()->cursor() as $author) {
            $this->assert_true($author->is_readonly());
            ++$count;
        }

        $this->assert_same(4, $count);
    }

    public function test_cursor_binds_dates()
    {
        $models = iterator_to_array(DatedCount::where('day', new ActiveRecord\DateTime('2026-01-02'))->cursor());

        $this->assert_same(1, count($models));
    }

    public function test_cursor_forwarded()
    {
        $this->assert_same(4, count(iterator_to_array(Author::cursor())));
    }

    public function test_cursor_is_a_snapshot_of_the_query_at_the_call()
    {
        $q = Author::where('author_id', 1);
        $c = $q->cursor();
        $q->where('author_id', 2);

        $this->assert_same([1], array_map(fn($m) => $m->author_id, iterator_to_array($c)));
    }

    public function test_stream_blocks_other_queries()
    {
        $count = 0;

        foreach (Author::new_query()->cursor(stream: true) as $a) {
            $this->assert_busy(fn() => Author::find(1), 'query()');
            ++$count;
        }

        $this->assert_same(4, $count);
        $this->assert_same('Tito', Author::find(1)->name);
    }

    public function test_stream_blocks_transactions()
    {
        $connection = Author::table()->connection();

        foreach (Author::new_query()->cursor(stream: true) as $a) {
            $this->assert_busy(fn() => $connection->transaction(), 'transaction()');
            $this->assert_busy(fn() => $connection->commit(), 'commit()');
        }

        $this->assert_false($connection->inTransaction());
    }

    // Final review F4 (maintainer decision 2026-11-09): rollback() closes an open stream instead of refusing. With the
    // arguments kept in exception traces (zend.exception_ignore_args=0, PHP's default without a php.ini), the
    // exception holds the cursor it was thrown beside, so the stream is still open when Model::transaction() rolls
    // back: rollback() used to throw ConnectionBusy, losing the exception and leaving the transaction open.
    public function test_exception_out_of_a_transaction_with_an_open_stream_rolls_back()
    {
        $connection = Author::table()->connection();
        $ignore_args = ini_set('zend.exception_ignore_args', '0');

        try {
            Author::transaction(function () {
                Author::create(['name' => 'Rolled back']);
                $cursor = Author::new_query()->cursor(stream: true);
                $cursor->current();
                $this->throw_beside($cursor);
            });
            $this->fail('RuntimeException expected');
        } catch (RuntimeException $e) {
            $this->assert_same('from the helper', $e->getMessage());
            // the trace still holds the cursor, as the scenario needs
            $this->assert_instance_of(Generator::class, $e->getTrace()[0]['args'][0] ?? null);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignore_args);
        }

        $this->assert_false($connection->inTransaction());
        $this->assert_null(Author::find_by_name('Rolled back'));
    }

    public function test_a_cursor_closed_by_rollback_throws_when_read_again()
    {
        $connection = Author::table()->connection();
        $connection->transaction();
        $cursor = Author::order_by('author_id')->cursor(stream: true);
        $this->assert_same(1, $cursor->current()->author_id);

        $connection->rollback();

        $this->assert_false($connection->inTransaction());
        $this->assert_same('Tito', Author::find(1)->name);
        $this->assert_closed_by_rollback($cursor);
    }

    // A stale cursor's finally, run after the rollback closed its stream, must not free a newer stream's connection
    public function test_a_cursor_closed_by_rollback_does_not_free_a_newer_stream()
    {
        $connection = Author::table()->connection();
        $connection->transaction();
        $old = Author::new_query()->cursor(stream: true);
        $old->current();
        $connection->rollback();

        $new = Author::order_by('author_id')->cursor(stream: true);
        $this->assert_same(1, $new->current()->author_id);
        unset($old);

        $this->assert_busy(fn() => Author::find(1), 'query()');
        $ids = [];
        for ($new->next(); $new->valid(); $new->next()) {
            $ids[] = $new->current()->author_id;
        }
        $this->assert_same([2, 3, 4], $ids);
        $this->assert_same('Tito', Author::find(1)->name);
    }

    public function test_savepoint_rollback_closes_the_stream()
    {
        $connection = Author::table()->connection();
        $connection->transaction();
        $connection->transaction();
        Author::create(['name' => 'Inner']);
        $cursor = Author::new_query()->cursor(stream: true);
        $cursor->current();

        $connection->rollback();

        $this->assert_true($connection->inTransaction());
        $this->assert_null(Author::find_by_name('Inner'));
        $this->assert_closed_by_rollback($cursor);

        $connection->rollback();
        $this->assert_false($connection->inTransaction());
    }

    // commit() still refuses: the stream stays open and readable, and the transaction with it
    public function test_commit_while_streaming_still_refuses()
    {
        $connection = Author::table()->connection();
        $connection->transaction();
        $cursor = Author::order_by('author_id')->cursor(stream: true);
        $cursor->current();

        $this->assert_busy(fn() => $connection->commit(), 'commit()');
        $this->assert_true($connection->inTransaction());
        $cursor->next();
        $this->assert_same(2, $cursor->current()->author_id);

        unset($cursor);
        $connection->rollback();
        $this->assert_false($connection->inTransaction());
    }

    // Model::transaction() whose commit is refused by a stream held outside the closure: the rollback of its catch
    // closes the stream, the transaction is rolled back, and ConnectionBusy comes out
    public function test_model_transaction_refused_commit_rolls_back()
    {
        $cursor = null;

        try {
            Author::transaction(function () use (&$cursor) {
                Author::create(['name' => 'Rolled back']);
                $cursor = Author::new_query()->cursor(stream: true);
                $cursor->current();
            });
            $this->fail('ConnectionBusy expected');
        } catch (ConnectionBusy $e) {
            $this->assert_string_starts_with('commit() cannot run while the connection streams a cursor', $e->getMessage());
        }

        $this->assert_false(Author::table()->connection()->inTransaction());
        $this->assert_null(Author::find_by_name('Rolled back'));
        $this->assert_closed_by_rollback($cursor);
    }

    public function test_second_stream_is_refused()
    {
        foreach (Author::new_query()->cursor(stream: true) as $a) {
            // the refusal names the user's call, not the internal method (pre-publication review)
            $this->assert_busy(fn() => Author::new_query()->cursor(stream: true)->current(), 'cursor(stream: true)');
            // the refused stream never held the connection, so it must not free it from the open one
            $this->assert_busy(fn() => Author::find(1), 'query()');
            break;
        }

        $this->assert_same('Tito', Author::find(1)->name);
    }

    // Pre-publication review: what looks like a read inside a stream is a statement too (README, the busy rule): a lazy
    // relationship load, and the first-use schema read of a model whose table is not loaded yet
    public function test_stream_refuses_a_lazy_relationship_load_and_a_first_schema_read()
    {
        Book::table(); // its schema read first: the relationship load below is then one query
        ActiveRecord\Table::clear_cache(Venue::class); // not loaded yet

        foreach (Author::new_query()->cursor(stream: true) as $a) {
            $this->assert_busy(fn() => $a->books, 'query()');
            $this->assert_busy(fn() => Venue::table(), 'query()');
            break;
        }

        $this->assert_same(['Ancient Art of Main Tanking'], array_map(fn($b) => $b->name, Author::find(1)->books));
        $this->assert_same('venues', Venue::table()->table);
    }

    public function test_break_from_direct_foreach_releases()
    {
        foreach (Author::new_query()->cursor(stream: true) as $a) {
            break;
        }

        $this->assert_same('Tito', Author::find(1)->name);
    }

    public function test_held_cursor_keeps_busy_until_unset()
    {
        $c = Author::new_query()->cursor(stream: true);

        foreach ($c as $a) {
            break;
        }

        $this->assert_busy(fn() => Author::find(1), 'query()');
        unset($c);
        $this->assert_same('Tito', Author::find(1)->name);
    }

    public function test_exception_in_body_releases()
    {
        try {
            foreach (Author::new_query()->cursor(stream: true) as $a) {
                throw new RuntimeException('from the body');
            }
        } catch (RuntimeException $e) {
            $this->assert_same('from the body', $e->getMessage());
        }

        $this->assert_same('Tito', Author::find(1)->name);
    }

    public function test_failing_stream_execute_leaves_connection_free()
    {
        try {
            Author::table()->connection()->stream_query('SELECT * FROM no_such_table');
            $this->fail('DatabaseException expected');
        } catch (DatabaseException) {
        }

        $this->assert_same('Tito', Author::find(1)->name);
    }

    public function test_failing_stream_cursor_leaves_connection_free()
    {
        try {
            iterator_to_array(Author::where_raw('no_such_column = 1')->cursor(stream: true));
            $this->fail('DatabaseException expected');
        } catch (DatabaseException) {
        }

        $this->assert_same('Tito', Author::find(1)->name);
    }

    // Final review F5: a failing cursor leaves its own SQL in Table::$last_sql, as find_by_sql() does
    public function test_failing_cursor_leaves_its_sql_in_last_sql()
    {
        foreach ([false, true] as $stream) {
            Author::find(1);
            $query = Author::where_raw('no_such_column = 1');

            try {
                iterator_to_array($query->cursor(stream: $stream));
                $this->fail('DatabaseException expected');
            } catch (DatabaseException) {
            }

            $this->assert_same($query->to_sql(), Author::table()->last_sql);
        }
    }

    public function test_stream_inside_transaction()
    {
        $connection = Author::table()->connection();

        $connection->transaction();
        $this->assert_same(4, count(iterator_to_array(Author::new_query()->cursor(stream: true))));
        $connection->commit();

        $connection->transaction();

        foreach (Author::new_query()->cursor(stream: true) as $a) {
            $this->assert_busy(fn() => $connection->commit(), 'commit()');
        }

        $connection->rollback();
        $this->assert_false($connection->inTransaction());
    }

    public function test_other_connection_unaffected()
    {
        $other = Connection::instance(Config::instance()->get_default_connection());
        $this->assert_not_same(Author::table()->connection(), $other);
        $ones = [];

        try {
            foreach (Author::new_query()->cursor(stream: true) as $a) {
                $ones[] = (int) $other->query_and_fetch_one('SELECT 1');
            }
        } finally {
            $other->close();
        }

        $this->assert_same([1, 1, 1, 1], $ones);
    }

    public function test_stream_yields_same_models()
    {
        $ids = fn(Generator $models) => array_map(fn($m) => $m->author_id, iterator_to_array($models));

        $this->assert_same(
            $ids(Author::order_by('author_id')->cursor()),
            $ids(Author::order_by('author_id')->cursor(stream: true))
        );
        $this->assert_same([0, 1, 2, 3], array_keys(iterator_to_array(Author::new_query()->cursor(stream: true))));
    }

    // The statement is closed on every way out of the loop, as the connection is released
    public function test_stream_closes_the_statement_on_every_exit()
    {
        $pdo = Author::table()->connection()->connection;
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [QueryCursorTestStatement::class]);
        QueryCursorTestStatement::$closed = 0;

        try {
            iterator_to_array(Author::new_query()->cursor(stream: true));
            $this->assert_same(1, QueryCursorTestStatement::$closed, 'end');

            foreach (Author::new_query()->cursor(stream: true) as $a) {
                break;
            }
            $this->assert_same(2, QueryCursorTestStatement::$closed, 'break');

            $c = Author::new_query()->cursor(stream: true);
            $c->current();
            unset($c);
            $this->assert_same(3, QueryCursorTestStatement::$closed, 'unset');

            try {
                foreach (Author::new_query()->cursor(stream: true) as $a) {
                    throw new RuntimeException();
                }
            } catch (RuntimeException) {
            }
            $this->assert_same(4, QueryCursorTestStatement::$closed, 'exception');

            foreach (Author::new_query()->cursor() as $a) {
                break;
            }
            $this->assert_same(5, QueryCursorTestStatement::$closed, 'buffered break');
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }
    }

    private function throw_beside(Generator $cursor): never
    {
        throw new RuntimeException('from the helper');
    }

    /**
     * Asserts that reading the cursor again throws: rollback() closed its stream.
     */
    private function assert_closed_by_rollback(Generator $cursor): void
    {
        try {
            $cursor->next();
        } catch (ActiveRecordException $e) {
            $this->assert_same(ActiveRecordException::class, $e::class);
            $this->assert_same('The cursor was closed by rollback(): its stream can no longer be read', $e->getMessage());

            return;
        }

        $this->fail('ActiveRecordException expected');
    }

    /**
     * Asserts the callable throws ConnectionBusy for the method named.
     */
    private function assert_busy(callable $run, string $method): void
    {
        try {
            $run();
        } catch (ConnectionBusy $e) {
            $this->assert_same(
                "$method cannot run while the connection streams a cursor (cursor(stream: true)): iterate the cursor to its end, or unset() it, first",
                $e->getMessage()
            );

            return;
        }

        $this->fail("ConnectionBusy expected from $method");
    }
}

/**
 * A statement that counts its closeCursor() calls.
 */
class QueryCursorTestStatement extends PDOStatement
{
    public static int $closed = 0;

    protected function __construct() {}

    public function closeCursor(): bool
    {
        ++self::$closed;

        return parent::closeCursor();
    }
}
