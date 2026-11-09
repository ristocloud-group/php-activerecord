<?php

use ActiveRecord\Connection;
use ActiveRecord\ActiveRecordException;
use Psr\Log\AbstractLogger;

/**
 * Query::lazy(): the models one at a time, read in pages by offset (spec §4.2).
 */
class QueryLazyTest extends DatabaseTest
{
    public function test_lazy_yields_models_with_sequential_keys()
    {
        $models = iterator_to_array(Author::lazy(3));

        $this->assert_same([0, 1, 2, 3], array_keys($models));
        $this->assert_same([1, 2, 3, 4], array_map(fn($m) => $m->author_id, array_values($models)));
    }

    public function test_lazy_validates_at_call()
    {
        $this->expectException(ActiveRecordException::class);
        $this->expectExceptionMessage('lazy(): the chunk size must be at least 1, 0 given');

        Author::lazy(0);
    }

    public function test_lazy_runs_no_sql_before_iteration()
    {
        $before = Author::table()->last_sql;
        $g = Author::where('author_id', 1)->lazy();

        $this->assert_same($before, Author::table()->last_sql);
        $this->assert_same(1, $g->current()->author_id);
    }

    public function test_lazy_stops_reading_when_consumer_stops()
    {
        $connection = Author::table()->connection();
        $property = new ReflectionProperty(Connection::class, 'logger');
        $original = $property->getValue($connection);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $property->setValue($connection, $logger);

        try {
            foreach (Author::lazy(1) as $a) {
                break;
            }
        } finally {
            $property->setValue($connection, $original);
        }

        $on_authors = array_filter($logger->messages, fn($sql) => str_contains($sql, 'authors'));
        $this->assert_same(1, count($on_authors));
    }
}
