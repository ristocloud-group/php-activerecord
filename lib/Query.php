<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * One query on one model class, and the engine that turns it into SQL.
 *
 * It holds a QueryState, which QueryCompiler renders as a SQLBuilder, and runs that statement
 * through its terminals (get, exists, ...). The legacy entries (find, count, exists, ...) hand it
 * their options array through from_options().
 *
 * @template TModel of Model
 * @package ActiveRecord
 */
class Query
{
    private QueryState $state;

    private ?Table $table = null;

    /**
     * @param class-string<TModel> $model_class
     */
    public function __construct(private readonly string $model_class)
    {
        $this->state = new QueryState();
    }

    /**
     * A query on $table's model that holds a legacy options array exactly as given (the one
     * Table::options_to_sql() receives). It reads and builds through $table itself, not through
     * the Table the model class would load, which can be another instance.
     *
     * @internal Serves the legacy entries; not a supported API.
     * @param array<string, mixed> $options
     * @return self<Model>
     */
    public static function from_options(Table $table, array $options): self
    {
        /** @var class-string<Model> $model_class */
        $model_class = $table->class->name;

        $query = new self($model_class);
        $query->table = $table;
        $query->state->legacy = $options;

        return $query;
    }

    /**
     * The SELECT this query describes, as a new SQLBuilder each time.
     *
     * @internal Serves the legacy entries; not a supported API.
     */
    public function to_sql_builder(): SQLBuilder
    {
        return (new QueryCompiler($this->table()))->compile_select($this->state);
    }

    /**
     * @internal Serves the legacy entries; not a supported API.
     */
    public function state(): QueryState
    {
        return $this->state;
    }

    /**
     * Runs the query and returns the models of the rows it selects, through Table::find_by_sql().
     * The legacy options 'readonly' (the models are readonly) and 'include' (the relationships to
     * eager load) apply.
     *
     * @return list<TModel>
     */
    public function get(): array
    {
        $options = $this->state->legacy ?? [];
        $sql = $this->to_sql_builder();
        $readonly = array_key_exists('readonly', $options) && $options['readonly'];
        $includes = array_key_exists('include', $options) ? $options['include'] : null;

        /** @var list<TModel> $models */
        $models = $this->table()->find_by_sql($sql->to_s(), $sql->get_where_values(), $readonly, $includes);

        return $models;
    }

    /**
     * Whether any row matches, without counting: the adapter's EXISTS() over the SELECT, which stops
     * at the first row. The statement that ran is left in Table::$last_sql.
     */
    public function exists(): bool
    {
        $table = $this->table();
        $conn = $table->connection();
        $sql = $this->to_sql_builder();
        $values = $table->format_date_bind_values($sql->get_where_values());
        $statement = $conn->exists_sql($sql->to_s());
        $table->last_sql = $statement;

        return (bool) (int) $conn->query_and_fetch_one($statement, $values);
    }

    /**
     * The execution of Model::count(), moved here: the SELECT of the legacy options is run (count()
     * has them select COUNT(*)) and the first column of its first row is returned, 0 when it
     * returns none (#34). A grouped count is that of the first group. Unlike exists(), it leaves
     * Table::$last_sql alone, as count() always did.
     *
     * It runs on $connection when one is given: Model::count() passes static::connection(), a
     * public static that a subclass can override, so that count() keeps calling that hook as it
     * always did. Without one it runs on the Table's connection.
     *
     * @internal Serves Model::count(); not a supported API.
     */
    public function legacy_count(?Connection $connection = null): int|string
    {
        $table = $this->table();
        $sql = $this->to_sql_builder();
        $values = $table->format_date_bind_values($sql->get_where_values());
        $row = ($connection ?? $table->connection())->query($sql->to_s(), $values)->fetch(\PDO::FETCH_NUM);

        return false === $row ? 0 : $row[0];
    }

    private function table(): Table
    {
        return $this->table ??= Table::load($this->model_class);
    }
}
