<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Turns a QueryState into the SQLBuilder that renders it: the one place that does.
 *
 * @internal Serves the Query engine; not a supported API.
 * @package ActiveRecord
 */
final class QueryCompiler
{
    /**
     * The statements are rendered for $connection when one is given, else for $table's: a bulk write
     * of Model gives the connection it always built its statement on (see Query::execute_update()).
     */
    public function __construct(private readonly Table $table, private readonly ?Connection $connection = null) {}

    /**
     * The SELECT of a state. A state that holds nothing selects every row.
     */
    public function compile_select(QueryState $state): SQLBuilder
    {
        return $this->compile_legacy($state->legacy ?? []);
    }

    /**
     * The UPDATE of a state. $set is a hash whose keys are the columns' real names and whose values
     * are prepared (see Query::execute_update()), or a raw SQL string; SQLBuilder rejects anything
     * else. The WHERE, ORDER BY and LIMIT are the state's (see apply_write_clauses()).
     *
     * @param array<string, mixed>|string $set
     */
    public function compile_update(QueryState $state, array|string $set): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->update($set);
        $this->apply_write_clauses($sql, $state->legacy ?? []);

        return $sql;
    }

    /**
     * The DELETE of a state, whose WHERE, ORDER BY and LIMIT are the state's (see
     * apply_write_clauses()). A state without conditions deletes every row.
     */
    public function compile_delete(QueryState $state): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->delete();
        $this->apply_write_clauses($sql, $state->legacy ?? []);

        return $sql;
    }

    /**
     * The INSERT of one row: the SQLBuilder calls Table::run_insert() made before the engine took
     * it over, moved as they were, so that it sends the same SQL. The row is a hash whose keys
     * are the columns' real names and whose values are prepared (see Query::execute_insert()).
     * $pk, an attribute or column name, is the column $sequence feeds, and $returning, another,
     * the one the statement returns (#47). SQLBuilder rejects what is no hash.
     *
     * @param array<string, mixed> $row
     */
    public function compile_insert(array $row, ?string $pk = null, ?string $sequence = null, ?string $returning = null): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->insert($row, null === $pk ? $pk : $this->table->real_column_name($pk), $sequence);

        if (null !== $returning) {
            $sql->returning($this->table->real_column_name($returning));
        }

        return $sql;
    }

    /**
     * The UPSERT of $row_count rows of $columns: the SQLBuilder calls Table::upsert() made before the
     * engine took it over, moved as they were. The names are the columns' real names, unquoted (see
     * Query::upsert()). $unique_by is the conflict target where the adapter names one, and $update the
     * columns a conflict overwrites: with none the statement is a plain INSERT, without a conflict clause.
     *
     * @param list<string> $columns
     * @param list<string> $unique_by
     * @param list<string> $update
     */
    public function compile_upsert(array $columns, int $row_count, array $unique_by, array $update): SQLBuilder
    {
        $sql = new SQLBuilder($this->connection(), $this->table->get_fully_qualified_table_name());
        $sql->upsert($columns, $row_count, $unique_by, $update);

        return $sql;
    }

    /**
     * The SELECT a legacy options array describes: the body Table::options_to_sql() had before
     * the engine took it over, moved as it was (its calls into Table go through $this->table), so
     * that every legacy entry sends the same SQL and the same bind values. Joins come before
     * conditions, so the keys of a hash that name the model's columns get its table (#35); any
     * other key, a joined table's, stays as written. A hash's names are read through the
     * model's aliases (#144), unless the options give the names to map as 'mapped_names'.
     *
     * @param array<string, mixed> $options
     */
    private function compile_legacy(array $options): SQLBuilder
    {
        $table = array_key_exists('from', $options) ? $options['from'] : $this->table->get_fully_qualified_table_name();
        $sql = new SQLBuilder($this->connection(), $table);

        if (array_key_exists('joins', $options)) {
            $sql->joins($this->table->create_joins($options['joins']));

            // a 'from' replaces the model's table by another, whose columns are not known here:
            // every key keeps naming it, as it always did. Otherwise the columns of the model
            // name it, and so do the ones every table has but its schema never lists (rowid, ...)
            if (!array_key_exists('from', $options)) {
                $sql->qualify_hash_keys([...array_keys($this->table->columns), ...$this->connection()->pseudo_columns()]);
            }

            // by default, an inner join will not fetch the fields from the joined table
            if (!array_key_exists('select', $options)) {
                $options['select'] = $this->table->get_fully_qualified_table_name() . '.*';
            }
        }

        if (array_key_exists('select', $options)) {
            $sql->select($options['select']);
        }

        $this->apply_conditions($sql, $options);

        if (array_key_exists('order', $options)) {
            $sql->order($options['order']);
        }

        if (array_key_exists('limit', $options)) {
            $sql->limit($options['limit']);
        }

        if (array_key_exists('offset', $options)) {
            $sql->offset($options['offset']);
        }

        if (array_key_exists('group', $options)) {
            $sql->group($options['group']);
        }

        if (array_key_exists('having', $options)) {
            $sql->having($options['having']);
        }

        return $sql;
    }

    /**
     * The WHERE of the 'conditions' of legacy options, if they have any: a string; a list, a
     * condition and its values as SQLBuilder::where() takes them; or a hash, whose names are read
     * through the model's aliases (#144), unless the options give the names to map as
     * 'mapped_names' (an empty list maps no alias), and are then named as the columns (#92).
     *
     * @param array<string, mixed> $options
     */
    private function apply_conditions(SQLBuilder $sql, array $options): void
    {
        if (!array_key_exists('conditions', $options)) {
            return;
        }

        if (!is_hash($options['conditions'])) {
            if (is_string($options['conditions'])) {
                $options['conditions'] = [$options['conditions']];
            }

            call_user_func_array([$sql,'where'], $options['conditions']);
        } else {
            // aliases and attribute names become column names (#92): the model's aliases,
            // unless the options give the names to map (an empty list maps no alias)
            $mapped_names = $options['mapped_names'] ?? $this->alias_attributes();
            $hashes = $this->table->map_names($options['conditions'], $mapped_names);

            if (1 === count($hashes)) {
                $sql->where($hashes[0]);
            } else {
                $sql->where_hashes($hashes);
            }
        }
    }

    /**
     * The clauses of a legacy UPDATE or DELETE: its conditions, read as a SELECT reads them (see
     * apply_conditions()), its limit and its order, the only options a write reads. Where the
     * adapter has no LIMIT on UPDATE and DELETE (Postgres), a limit picks the rows by the columns of
     * the primary key in a subquery (#92), or by the adapter's row identity without one.
     *
     * @param array<string, mixed> $options
     */
    private function apply_write_clauses(SQLBuilder $sql, array $options): void
    {
        $this->apply_conditions($sql, $options);

        if (isset($options['limit'])) {
            $sql->limit($options['limit']);
        }

        if (isset($options['order'])) {
            $sql->order($options['order']);
        }

        $sql->key_columns(array_map($this->table->real_column_name(...), $this->table->pk));
    }

    /**
     * The connection the statements are rendered for (see the constructor).
     */
    private function connection(): Connection
    {
        return $this->connection ?? $this->table->connection();
    }

    /**
     * The model's $alias_attribute: the names a hash of conditions can use for its columns.
     *
     * @return array<string, string>
     */
    private function alias_attributes(): array
    {
        /** @var class-string<Model> $model_class */
        $model_class = $this->table->class->name;

        return $model_class::$alias_attribute;
    }
}
