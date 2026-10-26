<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

use Closure;

/**
 * Renders conditions of the query builder, the shapes of QueryState::$wheres that Conditions reads, as SQL
 * and the values of their bind markers, each value prepared for the column it is compared with. It serves the
 * WHERE of a query (see QueryCompiler::where_clause()), and the conditions of a join and of a HAVING the same
 * way: whoever compiles them gives it the rule by which their columns are named.
 *
 * @internal Serves QueryCompiler; not a supported API.
 * @package ActiveRecord
 * @phpstan-import-type Where from QueryState
 */
final class ConditionCompiler
{
    /**
     * @param Table $table The table of the model, whose columns prepare the values (Table::process_data())
     * @param Connection $connection The connection the SQL is written for
     * @param Closure(string): array{sql: string, column: string} $naming How a condition names a column: the
     *   SQL, and the name its values are prepared by (see QueryCompiler::column())
     */
    public function __construct(private readonly Table $table, private readonly Connection $connection, private readonly Closure $naming) {}

    /**
     * Conditions of one level, each joined to the one before it by its AND or OR (that of the first is
     * dropped), and their values in order.
     *
     * @param list<Where> $wheres
     * @return array{sql: string, values: list<mixed>}
     */
    public function compile(array $wheres): array
    {
        $sql = '';
        $values = [];
        // SQL written by hand goes in parentheses beside another condition, which its OR could escape into
        $beside = count($wheres) > 1;

        foreach ($wheres as $i => $where) {
            [$condition, $condition_values] = $this->condition($where, $beside);
            $sql .= (0 === $i ? '' : " {$where['boolean']} ") . $condition;
            $values = [...$values, ...$condition_values];
        }

        return ['sql' => $sql, 'values' => $values];
    }

    /**
     * One condition (see QueryState::$wheres) and its values, each prepared for the column it is compared
     * with (see value()). $beside: another condition shares its level.
     *
     * @param Where $where
     * @return array{string, list<mixed>}
     */
    private function condition(array $where, bool $beside): array
    {
        return match ($where['type']) {
            'basic' => $this->comparison($where['column'], $where['operator'], $where['value']),
            'column' => [$this->column($where['first']) . " {$where['operator']} " . $this->column($where['second']), []],
            'raw' => [$beside ? "({$where['sql']})" : $where['sql'], $where['bindings']],
            'in' => $this->in($where['column'], $where['values'], $where['not']),
            'null' => [$this->column($where['column']) . ($where['not'] ? ' IS NOT NULL' : ' IS NULL'), []],
            'between' => $this->between($where['column'], $where['values'][0], $where['values'][1], $where['not']),
            'between_columns' => [
                $this->column($where['column']) . ($where['not'] ? ' NOT BETWEEN ' : ' BETWEEN ')
                    . $this->column($where['columns'][0]) . ' AND ' . $this->column($where['columns'][1]),
                [],
            ],
            'like' => [
                $this->connection->like_condition($this->column($where['column']), $where['case_sensitive'], $where['not']),
                [$this->connection->like_pattern($where['value'], $where['case_sensitive'])],
            ],
            'hash' => $this->hash($where['column'], $where['value']),
            'group' => $this->group($where['group'], $where['not']),
        };
    }

    /**
     * A column compared with a value by an operator, or with null: IS NULL by = and <=>, IS NOT NULL by
     * != and <> (Conditions refuses any other operator with null).
     *
     * @return array{string, list<mixed>}
     */
    private function comparison(string $name, string $operator, mixed $value): array
    {
        $column = $this->reference($name);

        if (null === $value) {
            return [$column['sql'] . (in_array($operator, ['!=', '<>'], true) ? ' IS NOT NULL' : ' IS NULL'), []];
        }

        return ["{$column['sql']} $operator ?", [$this->value($column['column'], $value)]];
    }

    /**
     * A column IN a list of values, or with $not NOT IN, by the rules of a hash of conditions (spec §5.2):
     * an empty list matches no row (1=0), or with $not every row (1=1); a null in the list is compared
     * with IS NULL ORed, or IS NOT NULL ANDed, and nulls only are that comparison alone.
     *
     * @param list<mixed> $values
     * @return array{string, list<mixed>}
     */
    private function in(string $name, array $values, bool $not): array
    {
        if ([] === $values) {
            return [$not ? '1=1' : '1=0', []];
        }

        $column = $this->reference($name);
        $listed = array_values(array_filter($values, fn(mixed $value): bool => null !== $value));
        $null = $column['sql'] . ($not ? ' IS NOT NULL' : ' IS NULL');

        if ([] === $listed) {
            return [$null, []];
        }

        $in = $column['sql'] . ($not ? ' NOT IN(' : ' IN(') . implode(',', array_fill(0, count($listed), '?')) . ')';
        $prepared = array_map(fn(mixed $value): mixed => $this->value($column['column'], $value), $listed);

        if (count($listed) === count($values)) {
            return [$in, $prepared];
        }

        return [$not ? "($in AND $null)" : "($in OR $null)", $prepared];
    }

    /**
     * A column BETWEEN two values, or with $not NOT BETWEEN.
     *
     * @return array{string, list<mixed>}
     */
    private function between(string $name, mixed $low, mixed $high, bool $not): array
    {
        $column = $this->reference($name);

        return [
            $column['sql'] . ($not ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?'),
            [$this->value($column['column'], $low), $this->value($column['column'], $high)],
        ];
    }

    /**
     * A column => value entry of a hash of conditions, rendered by Expressions as an entry of a legacy
     * conditions hash is (spec §5.2): a list is IN, an empty one 1=0, a null in it IS NULL ORed, and
     * a null IS NULL. The column is named by the rule, and its values prepared for it.
     *
     * @return array{string, list<mixed>}
     */
    private function hash(string $name, mixed $value): array
    {
        require_once __DIR__ . '/Expressions.php';
        $column = $this->reference($name);
        $value = is_array($value)
            ? array_map(fn(mixed $element): mixed => $this->value($column['column'], $element), $value)
            : $this->value($column['column'], $value);
        $expressions = new Expressions($this->connection, [$column['sql'] => $value]);

        return [$expressions->to_s(), array_flatten($expressions->values())];
    }

    /**
     * A group of conditions in parentheses, or with $not its negation, NOT (...).
     *
     * @return array{string, list<mixed>}
     */
    private function group(QueryState $group, bool $not): array
    {
        $wheres = $this->compile($group->wheres);

        return [($not ? 'NOT (' : '(') . $wheres['sql'] . ')', $wheres['values']];
    }

    /**
     * The SQL that names a column (see the constructor's $naming).
     */
    private function column(string $name): string
    {
        return $this->reference($name)['sql'];
    }

    /**
     * How the rule names a column: the SQL, and the name its values are prepared by.
     *
     * @return array{sql: string, column: string}
     */
    private function reference(string $name): array
    {
        return ($this->naming)($name);
    }

    /**
     * A value compared with a column, as the database receives it: prepared by Table::process_data() for
     * $column, so that a DateTime takes the format of the column's type, a date for a DATE column (spec
     * §5.2). A name that is no column of the model gets the format of a positional value, a date and time.
     */
    private function value(string $column, mixed $value): mixed
    {
        return $this->table->process_data([$column => $value])[$column];
    }
}
