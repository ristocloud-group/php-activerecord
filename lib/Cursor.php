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
     * statement's cursor is closed when the generator ends or is dropped.
     *
     * @return \Generator<int, TModel>
     */
    public function models(): \Generator
    {
        if ($this->stream) {
            throw new \LogicException('Streaming cursors are not implemented yet');
        }

        $sql = $this->sql->to_s();
        $values = $this->table->process_data($this->sql->select_bind_values());
        $sth = null;

        try {
            $sth = $this->table->connection()->query($sql, $values);
            $this->table->last_sql = $sql;

            while (($row = $sth->fetch())) {
                /** @var TModel $model */
                $model = $this->table->hydrate($row, $this->readonly);

                yield $model;
            }
        } finally {
            $sth?->closeCursor();
        }
    }
}
