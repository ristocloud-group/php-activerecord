<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The models of one SELECT, hydrated one row at a time (Query::cursor()).
 *
 * @internal Serves Query; not a supported API.
 * @template TModel of Model
 */
final class Cursor
{
    public function __construct(
        private readonly Table $table,
        private readonly SQLBuilder $sql,
        private readonly bool $readonly,
        private readonly bool $stream,
    ) {}

    /**
     * Sends the statement when the first model is asked for, and yields a model for each row, keyed from 0. The
     * statement's cursor is closed when the generator ends or is dropped. A stream marks the connection busy from its
     * execute until then (Connection::stream_query()); one that fails to execute leaves the connection free. A
     * rollback() of the connection closes a stream and frees the connection before that: the stream then can no longer
     * be read.
     *
     * @return \Generator<int, TModel>
     * @throws ConnectionBusy for a stream on a connection that already streams one
     * @throws ActiveRecordException when a stream is read again after a rollback() of the connection closed it
     * @throws DatabaseException when the statement fails to execute, or a row fails to be read (a stream that the
     *   server dropped, for example)
     */
    public function models(): \Generator
    {
        $sql = $this->sql->to_s();
        $values = $this->table->process_data($this->sql->select_bind_values());
        $connection = $this->table->connection();
        $sth = null;

        // set before the statement runs, as find_by_sql() sets it: a failing cursor leaves its own SQL there
        $this->table->last_sql = $sql;

        try {
            // $sth is set only once the statement has executed: a stream is released only if it held the connection
            $sth = $this->stream ? $connection->stream_query($sql, $values) : $connection->query($sql, $values);

            while (($row = $this->fetch($connection, $sth))) {
                /** @var TModel $model */
                $model = $this->table->hydrate($row, $this->readonly);

                yield $model;
            }
        } finally {
            // a stream that a rollback() closed is closed already, and the connection may stream another one since
            if (null !== $sth && (!$this->stream || $connection->streams($sth))) {
                try {
                    $sth->closeCursor();
                } finally {
                    if ($this->stream) {
                        $connection->release_stream($sth);
                    }
                }
            }
        }
    }

    /**
     * The next row of $sth, false after the last one. A database error while reading it is a DatabaseException, as
     * one while executing is (see Connection::query()).
     *
     * @return array<string, mixed>|false
     * @throws ActiveRecordException for a stream that a rollback() of the connection closed
     * @throws DatabaseException
     */
    private function fetch(Connection $connection, \PDOStatement $sth): array|false
    {
        if ($this->stream && !$connection->streams($sth)) {
            throw new ActiveRecordException('The cursor was closed by rollback(): its stream can no longer be read');
        }

        try {
            return $sth->fetch();
        } catch (\PDOException $e) {
            throw new DatabaseException($e);
        }
    }
}
