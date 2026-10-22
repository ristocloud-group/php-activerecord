<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * One query on one model class, and the engine that turns it into SQL.
 *
 * It holds a QueryState, which QueryCompiler renders as a SQLBuilder. The legacy entries
 * (find, count, exists, ...) hand it their options array through from_options().
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

    private function table(): Table
    {
        return $this->table ??= Table::load($this->model_class);
    }
}
