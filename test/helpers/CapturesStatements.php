<?php

use ActiveRecord\Connection;
use Psr\Log\AbstractLogger;

/**
 * For a test that counts the statements a piece of code sends.
 */
trait CapturesStatements
{
    /**
     * The statements the callable runs, as the connection of Author logs them. $each, if given, is called with each
     * statement as it is logged, before it runs. The connection's own logger is put back when it is done, or when it
     * throws.
     *
     * @param (callable(string): mixed)|null $each
     * @return list<string>
     */
    private function statements(callable $run, ?callable $each = null): array
    {
        $connection = Author::table()->connection();
        $property = new ReflectionProperty(Connection::class, 'logger');
        $original = $property->getValue($connection);
        $logger = new class ($each) extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            /** @var (callable(string): mixed)|null */
            private $each;

            public function __construct(?callable $each)
            {
                $this->each = $each;
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;

                if (null !== $this->each) {
                    ($this->each)((string) $message);
                }
            }
        };
        $property->setValue($connection, $logger);

        try {
            $run();
        } finally {
            $property->setValue($connection, $original);
        }

        return $logger->messages;
    }
}
