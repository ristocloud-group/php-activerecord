<?php

use ActiveRecord\Connection;
use Psr\Log\AbstractLogger;

/**
 * For a test that counts the statements a piece of code sends.
 */
trait CapturesStatements
{
    /**
     * The statements the callable runs, as the connection of Author logs them. The connection's own logger is put
     * back when it is done, or when it throws.
     *
     * @return list<string>
     */
    private function statements(callable $run): array
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
            $run();
        } finally {
            $property->setValue($connection, $original);
        }

        return $logger->messages;
    }
}
