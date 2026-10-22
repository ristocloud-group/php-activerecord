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
    public function __construct(private readonly Table $table) {}

    /**
     * The SELECT of a state. A state that holds nothing selects every row.
     */
    public function compile_select(QueryState $state): SQLBuilder
    {
        return $this->compile_legacy($state->legacy ?? []);
    }

    /**
     * The SELECT a legacy options array describes: the body Table::options_to_sql() had before
     * the engine took it over, moved as it was (its calls into Table go through $this->table), so
     * that every legacy entry sends the same SQL and the same bind values. Joins come before
     * conditions, so a hash's keys get qualified (#35). A hash's names are read through the
     * model's aliases (#144), unless the options give the names to map as 'mapped_names'.
     *
     * @param array<string, mixed> $options
     */
    private function compile_legacy(array $options): SQLBuilder
    {
        $table = array_key_exists('from', $options) ? $options['from'] : $this->table->get_fully_qualified_table_name();
        $sql = new SQLBuilder($this->table->connection(), $table);

        if (array_key_exists('joins', $options)) {
            $sql->joins($this->table->create_joins($options['joins']));

            // by default, an inner join will not fetch the fields from the joined table
            if (!array_key_exists('select', $options)) {
                $options['select'] = $this->table->get_fully_qualified_table_name() . '.*';
            }
        }

        if (array_key_exists('select', $options)) {
            $sql->select($options['select']);
        }

        if (array_key_exists('conditions', $options)) {
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
