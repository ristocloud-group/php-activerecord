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
     * execute until then (Connection::stream_query()); one that fails to execute leaves the connection free.
     *
     * @return \Generator<int, TModel>
     * @throws ConnectionBusy for a stream on a connection that already streams one
     */
    public function models(): \Generator
    {
        $sql = $this->sql->to_s();
        $values = $this->table->process_data($this->sql->select_bind_values());
        $connection = $this->table->connection();
        $sth = null;

        try {
            // $sth is set only once the statement has executed: a stream is released only if it held the connection
            $sth = $this->stream ? $connection->stream_query($sql, $values) : $connection->query($sql, $values);
            $this->table->last_sql = $sql;

            while (($row = $sth->fetch())) {
                /** @var TModel $model */
                $model = $this->table->hydrate($row, $this->readonly);

                yield $model;
            }
        } finally {
            try {
                $sth?->closeCursor();
            } finally {
                if ($this->stream && null !== $sth) {
                    $connection->release_stream();
                }
            }
        }
    }
}
