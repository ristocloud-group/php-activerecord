<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Interface for a table relationship.
 *
 * @package ActiveRecord
 */
interface InterfaceRelationship
{
    /**
     * @param array<int|string, mixed> $options
     */
    public function __construct($options = []);

    /**
     * @param array<int|string, mixed> $attributes
     * @return Model
     */
    public function build_association(Model $model, $attributes = []);

    /**
     * @param array<int|string, mixed> $attributes
     * @return Model
     */
    public function create_association(Model $model, $attributes = []);
}

/**
 * Abstract class that all relationships must extend from.
 *
 * @package ActiveRecord
 * @see http://www.phpactiverecord.org/guides/associations
 * @property list<string> $primary_key Primary key column(s) used for joins/eager-load conditions.
 *           Real property on {@see HasMany} (inherited by {@see HasOne}); computed on first
 *           access via {@see BelongsTo::__get()} for BelongsTo. Never touched on
 *           {@see HasAndBelongsToMany} (unimplemented stub).
 * @method void set_keys(string $model_class_name, bool $override = false) Infers/overwrites
 *           $foreign_key and $primary_key from a model class name. Implemented by
 *           {@see HasMany} (inherited by {@see HasOne}). Only ever invoked from
 *           {@see AbstractRelationship::query_and_attach_related_models_eagerly()} when
 *           $options['through'] is set, which is only a valid option for HasMany/HasOne —
 *           so it is never called on a BelongsTo/HasAndBelongsToMany instance.
 * @phpstan-type Relationship array{
 *     0: string, class_name?: string, class?: string, namespace?: string,
 *     foreign_key?: string|list<string>, primary_key?: string|list<string>,
 *     conditions?: mixed, select?: string, readonly?: bool,
 *     order?: string, group?: string, having?: string, limit?: int, offset?: int,
 *     through?: string, source?: string
 * }
 * @phpstan-type BelongsToRelationship array{
 *     0: string, class_name?: string, class?: string, namespace?: string,
 *     foreign_key?: string|list<string>,
 *     conditions?: mixed, select?: string, readonly?: bool
 * }
 */
abstract class AbstractRelationship implements InterfaceRelationship
{
    /**
     * Name to be used that will trigger call to the relationship.
     *
     * @var string
     */
    public $attribute_name;

    /**
     * Class name of the associated model.
     *
     * @var string
     */
    public $class_name;

    /**
     * Name of the foreign key.
     *
     * @var list<string>
     */
    public $foreign_key = [];

    /**
     * Options of the relationship.
     *
     * @var array<string, mixed>
     */
    protected $options = [];

    /**
     * Key columns that the key conditions must name table-qualified, as key => quoted
     * `table`.`column`: the owner key of a reverse-FK `through` lives on the middle table,
     * and the target table may have a column of the same name.
     *
     * @var array<string, string>
     */
    protected $qualified_keys = [];

    /**
     * Is the relationship single or multi.
     *
     * @var boolean
     */
    protected $poly_relationship = false;

    /**
     * List of valid options for relationships.
     *
     * @var list<string>
     */
    protected static $valid_association_options = ['class_name', 'class', 'foreign_key', 'conditions', 'select', 'readonly', 'namespace'];

    /**
     * Constructs a relationship.
     *
     * @param array<int|string, mixed> $options Options for the relationship (see {@link valid_association_options})
     * @return mixed
     */
    public function __construct($options = [])
    {
        if (!isset($options[0]) || !is_string($options[0]) || '' === $options[0]) {
            throw new RelationshipException('Relationship definition is missing its name (expected a non-empty string at index 0).');
        }

        $this->attribute_name = $options[0];
        $this->options = $this->merge_association_options($options);

        $relationship = strtolower(denamespace(get_called_class()));

        if ($relationship === 'hasmany' || $relationship === 'hasandbelongstomany') {
            $this->poly_relationship = true;
        }

        if (isset($this->options['conditions']) && !is_array($this->options['conditions'])) {
            $this->options['conditions'] = [$this->options['conditions']];
        }

        $class = $options['class'] ?? $options['class_name'] ?? null;
        $class = relationship_option_string($class, (string) $options[0], 'class_name');
        if (null !== $class) {
            $this->set_class_name($class);
        }

        $this->attribute_name = strtolower(Inflector::instance()->variablize($this->attribute_name));

        if (!$this->foreign_key) {
            $fk = relationship_option_key_list($options['foreign_key'] ?? null, (string) $options[0], 'foreign_key');
            if ($fk) {
                $this->foreign_key = $fk;
            }
        }
    }

    /**
     * @return Table
     */
    public function get_table()
    {
        return Table::load($this->class_name);
    }

    /**
     * What is this relationship's cardinality?
     *
     * @return bool
     */
    public function is_poly()
    {
        return $this->poly_relationship;
    }

    /**
     * Eagerly loads relationships for $models.
     *
     * This method takes an array of models, collects PK or FK (whichever is needed for relationship), then queries
     * the related table by PK/FK and attaches the array of returned relationships to the appropriately named relationship on
     * $models.
     *
     * @param Table $table
     * @param list<Model> $models array of model objects
     * @param list<array<string, mixed>> $attributes array of attributes from $models
     * @param array<int|string, mixed> $includes array of eager load directives
     * @param list<string> $query_keys -> key(s) to be queried for on included/related table
     * @param list<string> $model_values_keys -> key(s)/value(s) to be used in query from model which is including
     * @return void
     */
    protected function query_and_attach_related_models_eagerly(Table $table, $models, $attributes, $includes = [], $query_keys = [], $model_values_keys = [])
    {
        $values = [];
        $options = $this->options;
        $inflector = Inflector::instance();
        $query_key = $query_keys[0];
        $model_values_key = $model_values_keys[0];
        // GH #40: composite keys are queried and matched on every pair, as load() does
        $pairs = $this instanceof HasMany && empty($options['through']) ? min(count($query_keys), count($model_values_keys)) : 1;

        foreach (1 === $pairs ? $attributes : [] as $column => $value) {
            $values[] = $value[$inflector->variablize($model_values_key)];
        }

        $values = [$values];
        $conn = $table->conn;
        if (null === $conn) {
            throw new DatabaseException('No database connection established for ' . $table->class->getName());
        }
        $qualified_keys = [];
        $match_key = null;

        if (!empty($includes)) {
            $options['include'] = $includes;
        }

        if (!empty($options['through'])) {
            $through_relationship = $table->get_relationship($options['through'], true);
            if (null === $through_relationship) {
                throw new RelationshipException("Relationship named {$options['through']} has not been declared for class: {$table->class->getName()}");
            }
            $through_table = $through_relationship->get_table();
            $source = $this->resolve_source_relationship($through_relationship);

            if ($source instanceof HasMany) {
                // Reverse-FK chain (issue #22): join the middle table and expose
                // its owner FK (e.g. books.author_id) aliased onto every target
                // row so the matching loop below can partition per owner. The
                // owner FK stays as $query_key (already the owner FK here); its
                // key condition names it qualified, as the target table may have
                // a column of the same name.
                $options['joins'] = $this->construct_through_reverse_join_sql($through_table, $source);
                $target_name = $this->get_table()->get_fully_qualified_table_name();
                $middle_name = $through_table->get_fully_qualified_table_name();
                $match_key = $this->middle_key_alias($query_key);
                $options['select'] = "$target_name.*, $middle_name.$query_key AS $match_key";
                $qualified_keys = [$query_key => $this->qualified_column($through_table, $query_key)];
            } else {
                // Historical join-table / belongs_to shape.
                $pk = $this->primary_key;
                $fk = $this->foreign_key;

                $this->set_keys($this->get_table()->class->getName(), true);
                $options['joins'] = $this->construct_inner_join_sql($through_table, true);

                // GH #27: expose the middle table's owner FK (e.g. events.venue_id)
                // aliased onto every target row — same trick as the reverse-FK
                // branch above — so the matching loop below can partition the rows
                // per parent. (This used to null out $query_key, which made the
                // loop attach every fetched row to every parent.)
                $target_name = $this->get_table()->get_fully_qualified_table_name();
                $middle_name = $through_table->get_fully_qualified_table_name();
                $select = isset($options['select']) && is_string($options['select'])
                    ? $options['select']
                    : "$target_name.*";
                $match_key = $this->middle_key_alias($query_key);
                $options['select'] = "$select, $middle_name.$query_key AS $match_key";
                // the key is the middle table's (selected from it above): name it qualified
                $qualified_keys = [$query_key => $this->qualified_column($through_table, $query_key)];

                // reset keys
                $this->primary_key = $pk;
                $this->foreign_key = $fk;
            }
        }

        // built after the through block, which may qualify the key (GH #40: composite pairs
        // apply only without `through`, where no key is qualified)
        $conditions = 1 === $pairs
            ? SQLBuilder::create_conditions_from_columns($conn, [$query_key], $values, $qualified_keys) ?? []
            : $this->create_eager_conditions_from_pairs($conn, array_slice($query_keys, 0, $pairs), array_slice($model_values_keys, 0, $pairs), $attributes);

        // Accept the hash form (GH #13): normalize it to the positional shape
        // before merging so the branch below (and add_condition) can consume it.
        if (isset($options['conditions'])) {
            $options['conditions'] = $this->to_positional_conditions($conn, $options['conditions']);
        }

        if (isset($options['conditions']) && strlen($options['conditions'][0]) > 1) {
            // Group the declared fragment so its own OR cannot swallow the key
            // condition ("a OR b AND fk IN(?)" would match "a" for any owner).
            $options['conditions'][0] = '(' . $options['conditions'][0] . ')';
            if (1 === $pairs) {
                Utils::add_condition($options['conditions'], $conditions);
            } else {
                // one bind per placeholder: add_condition() would nest them all in one
                $options['conditions'][0] .= ' AND ' . array_shift($conditions);
                array_push($options['conditions'], ...$conditions);
            }
        } else {
            $options['conditions'] = $conditions;
        }

        $options = $this->unset_non_finder_options($options);

        $class = $this->class_name;
        [$skip, $take] = $this->eager_window($options);

        if (0 === $take) {
            // limit 0: no owner gets a child (#34), no query needed
            foreach ($models as $model) {
                $model->set_relationship_from_eager_load(null, $this->attribute_name);
            }

            return;
        }

        $related_models = $class::find('all', $options);
        $used_models = [];
        $model_values_key = $inflector->variablize($model_values_key);
        $query_key = $inflector->variablize($match_key ?? $query_key);

        $pair_keys = [];
        for ($i = 1; $i < $pairs; ++$i) {
            $pair_keys[$inflector->variablize($query_keys[$i])] = $inflector->variablize($model_values_keys[$i]);
        }
        $key_matches = self::eager_key_matcher($class::table());

        foreach ($models as $model) {
            $matches = $skipped = 0;
            $key_to_match = $model->$model_values_key;

            foreach ($related_models as $related) {
                /** @var Model $related */
                if (empty($query_key) || $key_matches($related->$query_key, $key_to_match, $pairs > 1, $query_key)) {
                    foreach ($pair_keys as $related_key => $owner_key) {
                        // the owner value the key condition was built from
                        if (!$key_matches($related->$related_key, $model->attributes()[$owner_key] ?? null, true, $related_key)) {
                            continue 2;
                        }
                    }

                    if ($skipped < $skip) {
                        ++$skipped;
                        continue;
                    }

                    if (null !== $take && $matches >= $take) {
                        break;
                    }

                    $hash = spl_object_hash($related);

                    if (in_array($hash, $used_models)) {
                        $model->set_relationship_from_eager_load(clone($related), $this->attribute_name);
                    } else {
                        $model->set_relationship_from_eager_load($related, $this->attribute_name);
                    }

                    $used_models[] = $hash;
                    $matches++;

                    if ($this instanceof HasOne) {
                        break; // GH #40: the first match in the declared order, as load()'s find('first')
                    }
                }
            }

            if (0 === $matches) {
                $model->set_relationship_from_eager_load(null, $this->attribute_name);
            }
        }
    }

    /**
     * GH #40: the eager key conditions for composite keys, one "(fk1 = ? AND fk2 = ?)" group
     * per distinct owner key, OR'ed: every pair is queried as load() queries it (a null part
     * as IS NULL). An owner whose key values are all null is left out, as load() finds
     * nothing for it.
     *
     * @param list<string> $query_keys
     * @param list<string> $model_values_keys
     * @param list<array<string, mixed>> $attributes
     * @return list<mixed>
     */
    private function create_eager_conditions_from_pairs(Connection $conn, array $query_keys, array $model_values_keys, array $attributes): array
    {
        $inflector = Inflector::instance();
        $groups = $binds = [];

        foreach ($attributes as $owner) {
            $key = [];
            foreach ($model_values_keys as $model_values_key) {
                $key[] = $owner[$inflector->variablize($model_values_key)] ?? null;
            }

            $signature = serialize($key);
            if (all(null, $key) || isset($groups[$signature])) {
                continue;
            }

            $condition = SQLBuilder::create_conditions_from_columns($conn, $query_keys, $key) ?? [''];
            $groups[$signature] = '(' . array_shift($condition) . ')';
            array_push($binds, ...$condition);
        }

        // no owner key at all (HasMany::load_eagerly() does not get here): match nothing
        return array_merge(['(' . ([] === $groups ? '1 = 0' : implode(' OR ', $groups)) . ')'], $binds);
    }

    /**
     * GH #40: a declared limit/offset applies to each owner's children in the eager load, as
     * it does in load(): it is taken off the query here and returned as [rows to skip, rows to
     * take] for the matching. A has_one ignores both, as load()'s find('first') does. A
     * negative limit or offset stays on the query, as before.
     *
     * @param array<string, mixed> $options
     * @param-out array<string, mixed> $options
     * @return array{int, int|null}
     */
    private function eager_window(array &$options): array
    {
        if (!($this instanceof HasMany) || (!array_key_exists('limit', $options) && !array_key_exists('offset', $options))) {
            return [0, null];
        }

        // read like SQLBuilder::limit() / offset() (#34): 0 is LIMIT 0, null no limit
        $limit = $options['limit'] ?? null;
        $take = (0 === $limit || '0' === $limit) ? 0 : (intval($limit) ?: null);
        $skip = intval($options['offset'] ?? 0);

        if ($this instanceof HasOne) {
            [$skip, $take] = [0, null];
        } elseif ($skip < 0 || $take < 0) {
            return [0, null];
        }

        unset($options['limit'], $options['offset']);

        return [$skip, $take];
    }

    /**
     * GH #40: compares a child's key with an owner's in the eager load, as the database did
     * when it found the child: PHP ==, as always, and on MySQL/MariaDB two strings that differ
     * only by case also match, as under their default case-insensitive collations
     * (utf8mb4_0900_ai_ci, utf8mb4_uca1400_ai_ci) - only when the child's key column
     * ($related_column) is a text column (not binary/blob, which compare byte for byte) and both
     * keys are valid UTF-8, so no two distinct byte strings fold together. For a part of a
     * composite key ($strict_null) null matches only null, as IS NULL.
     *
     * @param Table $related the related model's table, whose connection found the children
     * @return \Closure(mixed, mixed, bool, string): bool
     */
    private static function eager_key_matcher(Table $related): \Closure
    {
        $ignore_case = $related->conn instanceof MysqlAdapter && function_exists('mb_convert_case');
        $text_columns = $folded = [];

        $is_text_column = function (string $column) use ($related, &$text_columns): bool {
            if (!array_key_exists($column, $text_columns)) {
                $meta = $related->get_column_by_inflected_name($column);
                $text_columns[$column] = null !== $meta && Column::STRING === $meta->type
                    && !preg_match('/binary|blob/i', (string) $meta->raw_type);
            }

            return $text_columns[$column];
        };

        $fold = function (string $key) use (&$folded): string {
            return $folded[$key] ??= mb_convert_case($key, MB_CASE_FOLD, 'UTF-8');
        };

        return function (mixed $related_key, mixed $owner_key, bool $strict_null, string $related_column) use ($ignore_case, $is_text_column, $fold): bool {
            if ($strict_null && (null === $related_key || null === $owner_key)) {
                return $related_key === $owner_key;
            }

            if ($related_key == $owner_key) {
                return true;
            }

            return $ignore_case && is_string($related_key) && is_string($owner_key) && $is_text_column($related_column)
                && mb_check_encoding($related_key, 'UTF-8') && mb_check_encoding($owner_key, 'UTF-8')
                && $fold($related_key) === $fold($owner_key);
        };
    }

    /**
     * Creates a new instance of specified {@link Model} with the attributes pre-loaded.
     *
     * @param Model $model The model which holds this association
     * @param array<int|string, mixed> $attributes Hash containing attributes to initialize the model with
     * @return Model
     */
    public function build_association(Model $model, $attributes = [])
    {
        $class_name = $this->class_name;
        /** @var Model $associated */
        $associated = new $class_name($attributes);
        return $associated;
    }

    /**
     * Creates a new instance of {@link Model} and invokes save.
     *
     * @param Model $model The model which holds this association
     * @param array<int|string, mixed> $attributes Hash containing attributes to initialize the model with
     * @return Model
     */
    public function create_association(Model $model, $attributes = [])
    {
        $class_name = $this->class_name;
        $new_record = $class_name::create($attributes);
        return $this->append_record_to_associate($model, $new_record);
    }

    /**
     * @return Model
     */
    protected function append_record_to_associate(Model $associate, Model $record)
    {
        $association = & $associate->{$this->attribute_name};

        if ($this->poly_relationship) {
            $association[] = $record;
        } else {
            $association = $record;
        }

        return $record;
    }

    /**
     * @param array<int|string, mixed> $options
     * @return array<string, mixed>
     */
    protected function merge_association_options($options)
    {
        // BelongsTo does not redeclare the list (static:: is self::): keep each option once
        $available_options = array_unique(array_merge(self::$valid_association_options, static::$valid_association_options));

        foreach ($options as $key => $ignored) {
            if (is_int($key)) {
                continue; // positional relationship name at index 0
            }
            if (!in_array($key, $available_options, true)) {
                throw new RelationshipException(sprintf(
                    "Unknown option '%s' for relationship '%s'. Valid options: %s.",
                    $key,
                    is_string($options[0] ?? null) ? $options[0] : '?',
                    implode(', ', $available_options)
                ));
            }
        }

        $valid_options = array_intersect_key(array_flip($available_options), $options);

        foreach ($valid_options as $option => $v) {
            $valid_options[$option] = $options[$option];
        }

        return $valid_options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function unset_non_finder_options($options)
    {
        foreach (array_keys($options) as $option) {
            if (!in_array($option, Model::$VALID_OPTIONS)) {
                unset($options[$option]);
            }
        }
        return $options;
    }

    /**
     * Infers the $this->class_name based on $this->attribute_name.
     *
     * Will try to guess the appropriate class by singularizing and uppercasing $this->attribute_name.
     *
     * @return void
     * @see attribute_name
     */
    protected function set_inferred_class_name()
    {
        $singularize = ($this instanceof HasMany ? true : false);
        $this->set_class_name(classify($this->attribute_name, $singularize));
    }

    /**
     * @param string $class_name
     * @return void
     */
    protected function set_class_name($class_name)
    {
        if (!has_absolute_namespace($class_name) && isset($this->options['namespace'])) {
            $class_name = $this->options['namespace'] . '\\' . $class_name;
        }

        $reflection = Reflections::instance()->add($class_name)->get($class_name);

        if (!$reflection->isSubClassOf('ActiveRecord\\Model')) {
            throw new RelationshipException("'$class_name' must extend from ActiveRecord\\Model");
        }

        $this->class_name = $class_name;
    }

    /**
     * Normalizes a declared `conditions` option into the positional
     * [<sql fragment>, ...bind values] form the relationship condition-merging
     * paths expect.
     *
     * Finders accept conditions in two shapes: a positional SQL fragment
     * (`['name IN(?)', $binds]`) and a hash (`['name' => $value_or_list]`).
     * The relationship merge path ({@see Utils::add_condition}) only understands
     * the positional shape, so the hash form used to be dropped silently (GH #13).
     * We convert a hash through {@see Expressions} — the very machinery finders
     * use — so both shapes reach parity: a scalar value becomes `col = ?`, a
     * list becomes `col IN(?, ...)`, and a null value becomes `col IS NULL`.
     * A positional/fragment condition is returned unchanged.
     *
     * @param array<int|string, mixed> $conditions
     * @return array<int|string, mixed>
     */
    protected function to_positional_conditions(Connection $conn, array $conditions)
    {
        if (is_hash($conditions)) {
            require_once 'Expressions.php';
            $expressions = new Expressions($conn, $conditions);
            return array_merge([$expressions->to_s()], array_flatten($expressions->values()));
        }

        return $conditions;
    }

    /**
     * @param list<string> $condition_keys
     * @param list<string> $value_keys
     * @return array<int, mixed>|null
     */
    protected function create_conditions_from_keys(Model $model, $condition_keys = [], $value_keys = [])
    {
        $condition_values = array_values($model->get_values_for($value_keys));

        // return null if all the foreign key values are null so that we don't try to do a query like "id is null"
        if (all(null, $condition_values)) {
            return null;
        }

        $model_table = Table::load(get_class($model));
        $model_conn = $model_table->conn;
        if (null === $model_conn) {
            throw new DatabaseException('No database connection established for ' . $model_table->class->getName());
        }
        // the key columns as a list: a column named e.g. black_and_white stays one column (#53)
        $conditions = SQLBuilder::create_conditions_from_columns($model_conn, $condition_keys, $condition_values, $this->qualified_keys) ?? [];

        # add_condition() mutates its first argument by reference, so we must merge
        # into a *local* copy — never $this->options['conditions'] directly, or the
        # declared options get corrupted across loads. to_positional_conditions()
        # also folds the hash form down to positional (GH #13) and returns a fresh
        # array for it, so the decoupling holds for both shapes.
        if (isset($this->options['conditions'])) {
            $options_conditions = $this->to_positional_conditions($model_conn, $this->options['conditions']);
        } else {
            $options_conditions = [];
        }

        if ([] === $options_conditions || [] === $conditions) {
            $result = Utils::add_condition($options_conditions, $conditions);
            /** @var array<int, mixed>|null $result */
            return $result;
        }

        # Merging into a declared condition: add_condition() would append ALL the
        # key values as ONE nested array, so with composite keys the first key
        # marker received every value (expanded to "?,?") and the next marker none
        # (ExpressionsException). Append one bind per key marker instead, each in
        # the one-element-array shape add_condition() gives a single key: the
        # single-key binds (and the Expressions path SQLBuilder takes for them)
        # are unchanged, composite keys get one value per placeholder.
        # The declared fragment is parenthesized so its own OR cannot swallow the
        # key condition ("a OR b AND fk=?" would match "a" for any owner).
        $options_conditions[0] = '(' . $options_conditions[0] . ') AND ' . array_shift($conditions);

        foreach ($conditions as $value) {
            $options_conditions[] = array_flatten([$value]);
        }

        /** @var array<int, mixed> $options_conditions */
        return $options_conditions;
    }

    /**
     * Creates INNER JOIN SQL for associations.
     *
     * @param Table $from_table the table used for the FROM SQL statement
     * @param bool $using_through is this a THROUGH relationship?
     * @param string $alias a table alias for when a table is being joined twice
     * @return string SQL INNER JOIN fragment
     */
    public function construct_inner_join_sql(Table $from_table, $using_through = false, $alias = null)
    {
        if ($using_through) {
            $join_table = $from_table;
            $join_table_name = $from_table->get_fully_qualified_table_name();
            $from_table_name = Table::load($this->class_name)->get_fully_qualified_table_name();
        } else {
            $join_table = Table::load($this->class_name);
            $join_table_name = $join_table->get_fully_qualified_table_name();
            $from_table_name = $from_table->get_fully_qualified_table_name();
        }

        // need to flip the logic when the key is on the other table
        if ($this instanceof HasMany) {
            $this->set_keys($from_table->class->getName());

            // set_keys() always assigns $this->primary_key (either from options or
            // from Table::load()->pk); this is only null before the first call.
            if (null === $this->primary_key) {
                throw new RelationshipException("Could not determine primary key for relationship '{$this->attribute_name}'");
            }

            if ($using_through) {
                $foreign_key = $this->primary_key[0];
                $join_primary_key = $this->foreign_key[0];
            } else {
                $join_primary_key = $this->foreign_key[0];
                $foreign_key = $this->primary_key[0];
            }
        } else {
            $foreign_key = $this->foreign_key[0];
            $join_primary_key = $this->primary_key[0];
        }

        if (!is_null($alias)) {
            $alias_table = $this->get_table();
            $alias_conn = $alias_table->conn;
            if (null === $alias_conn) {
                throw new DatabaseException('No database connection established for ' . $alias_table->class->getName());
            }
            $aliased_join_table_name = $alias = $alias_conn->quote_name($alias);
            $alias .= ' ';
        } else {
            $aliased_join_table_name = $join_table_name;
        }

        return "INNER JOIN $join_table_name {$alias}ON($from_table_name.$foreign_key = $aliased_join_table_name.$join_primary_key)";
    }

    /**
     * Resolves the association on the *through* (middle) model that points at
     * this relationship's target — the equivalent of Rails' "source".
     *
     * The middle model is the target of the `through` relationship. We probe its
     * declared associations by name, trying (in order): the singular of our
     * attribute name, that singular re-pluralized, and the attribute name
     * itself. The first that resolves wins; `null` means none matched (callers
     * then keep the historical join-table behavior).
     *
     * @param AbstractRelationship $through the owner's `through` relationship
     * @return AbstractRelationship|null
     */
    protected function resolve_source_relationship(AbstractRelationship $through): ?AbstractRelationship
    {
        $middle_table = $through->get_table();
        $singular = Utils::singularize($this->attribute_name);

        foreach ([$singular, Utils::pluralize($singular), $this->attribute_name] as $name) {
            $source = $middle_table->get_relationship($name);
            if (null !== $source) {
                return $source;
            }
        }

        return null;
    }

    /**
     * `table`.`column`, quoted with that table's own connection.
     */
    protected function qualified_column(Table $table, string $column): string
    {
        $conn = $table->conn;
        if (null === $conn) {
            throw new DatabaseException('No database connection established for ' . $table->class->getName());
        }

        return $table->get_fully_qualified_table_name() . '.' . $conn->quote_name($column);
    }

    /**
     * Whether $table has a column named $column: exactly on Postgres (quoted names are
     * case-sensitive), ignoring ASCII case elsewhere.
     */
    protected static function table_has_column(Table $table, string $column): bool
    {
        $pgsql = $table->conn instanceof PgsqlAdapter;

        foreach (array_keys($table->columns) as $name) {
            $name = (string) $name;

            if ($name === $column || (!$pgsql && 0 === strcasecmp($name, $column))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name the eager `through` query selects the middle table's key under. It is the
     * key itself, unless the target table has a column of that name: the alias would
     * overwrite it on every target row, so a private alias is used for the matching.
     */
    protected function middle_key_alias(string $key): string
    {
        return self::table_has_column($this->get_table(), $key) ? "ar_through_$key" : $key;
    }

    /**
     * Builds the INNER JOIN that hops from this relationship's target table to
     * the `through` (middle) table for the reverse-FK (has_many→has_many) chain:
     *
     *     INNER JOIN <middle> ON(<target>.<source_fk> = <middle>.<source_pk>)
     *
     * The keys come from the middle model's own `has_many` to the target
     * ($source). Kept separate from {@see construct_inner_join_sql()} so the
     * historical join-table path is left untouched (issue #22, Approach B).
     *
     * @param Table $middle_table the `through` model's table
     * @param HasMany $source the middle→target has_many association
     * @return string
     */
    protected function construct_through_reverse_join_sql(Table $middle_table, HasMany $source): string
    {
        $source->set_keys($middle_table->class->getName());
        if (null === $source->primary_key) {
            throw new RelationshipException("Could not determine source primary key for relationship '{$this->attribute_name}'");
        }

        $target_name = $this->get_table()->get_fully_qualified_table_name();
        $middle_name = $middle_table->get_fully_qualified_table_name();
        $target_fk = $source->foreign_key[0]; // FK on the target table (e.g. book_id)
        $middle_pk = $source->primary_key[0]; // middle PK (e.g. book_id)

        return "INNER JOIN $middle_name ON($target_name.$target_fk = $middle_name.$middle_pk)";
    }

    /**
     * This will load the related model data.
     *
     * @param Model $model The model this relationship belongs to
     * @return Model|array<int, Model>|null
     */
    abstract public function load(Model $model);

    /**
     * Eagerly loads the related model data for a set of models.
     *
     * @param list<Model> $models The models to load the association for
     * @param list<array<string, mixed>> $attributes The attributes from the related table that were pre-fetched for this relationship
     * @param array<int|string, mixed> $includes The nested includes to eager load on the associated models
     * @param Table $table The Table for the class that owns this relationship
     * @return void
     */
    abstract public function load_eagerly($models, $attributes, $includes, Table $table);
};

/**
 * One-to-many relationship.
 *
 * <code>
 * # Table: people
 * # Primary key: id
 * # Foreign key: school_id
 * class Person extends ActiveRecord\Model {}
 *
 * # Table: schools
 * # Primary key: id
 * class School extends ActiveRecord\Model {
 *   static $has_many = array(
 *     array('people')
 *   );
 * });
 * </code>
 *
 * Example using options:
 *
 * <code>
 * class Payment extends ActiveRecord\Model {
 *   static $belongs_to = array(
 *     array('person'),
 *     array('order')
 *   );
 * }
 *
 * class Order extends ActiveRecord\Model {
 *   static $has_many = array(
 *     array('people',
 *           'through'    => 'payments',
 *           'select'     => 'people.*, payments.amount',
 *           'conditions' => 'payments.amount < 200')
 *     );
 * }
 * </code>
 *
 * @package ActiveRecord
 * @see http://www.phpactiverecord.org/guides/associations
 * @see valid_association_options
 */
class HasMany extends AbstractRelationship
{
    /**
     * Valid options to use for a {@link HasMany} relationship.
     *
     * <ul>
     * <li><b>limit/offset:</b> limit the number of records</li>
     * <li><b>primary_key:</b> name of the primary_key of the association (defaults to "id")</li>
     * <li><b>group:</b> GROUP BY clause</li>
     * <li><b>order:</b> ORDER BY clause</li>
     * <li><b>through:</b> name of a model</li>
     * </ul>
     *
     * @var list<string>
     */
    protected static $valid_association_options = ['primary_key', 'order', 'group', 'having', 'limit', 'offset', 'through', 'source'];

    /** @var list<string>|null */
    protected $primary_key;

    /** @var list<string> The declared `primary_key` option ([] when the table pk is inferred). */
    private array $declared_primary_key = [];

    /** @var string|null */
    private $through;

    /** @var bool|null Unset until {@see load()} runs once; isset() is the deliberate init-guard. */
    private $initialized;

    /**
     * Constructs a {@link HasMany} relationship.
     *
     * @param array<int|string, mixed> $options Options for the association
     * @return HasMany
     */
    public function __construct($options = [])
    {
        parent::__construct($options);

        $through = relationship_option_string($options['through'] ?? null, (string) $options[0], 'through');
        if (null !== $through) {
            $this->through = $through;

            $source = relationship_option_string($options['source'] ?? null, (string) $options[0], 'source');
            if (null !== $source) {
                $this->set_class_name($source);
            }
        }

        if (!$this->primary_key) {
            $pk = relationship_option_key_list($options['primary_key'] ?? null, (string) $options[0], 'primary_key');
            if ($pk) {
                $this->primary_key = $this->declared_primary_key = $pk;
            }
        }

        if (!$this->class_name) {
            $this->set_inferred_class_name();
        }
    }

    /**
     * GH #40: a declared `primary_key` keys the eager load and the build_/create_ builders
     * as it keys {@see load()}. Without one, both keep keying off the table pk as before; a
     * `through` relationship is left as it was.
     */
    private function keys_off_declared_primary_key(): bool
    {
        return [] !== $this->declared_primary_key && null === $this->through;
    }

    /**
     * @param string $model_class_name
     * @param bool $override
     * @return void
     */
    protected function set_keys($model_class_name, $override = false)
    {
        //infer from class_name
        if (!$this->foreign_key || $override) {
            $this->foreign_key = [Inflector::instance()->keyify($model_class_name)];
        }

        if (!$this->primary_key || $override) {
            $this->primary_key = Table::load($model_class_name)->pk;
        }
    }

    /**
     * @return Model|array<int, Model>|null
     */
    public function load(Model $model)
    {
        $class_name = $this->class_name;
        $this->set_keys(get_class($model));

        // since through relationships depend on other relationships we can't do
        // this initiailization in the constructor since the other relationship
        // may not have been created yet and we only want this to run once
        if (!isset($this->initialized)) {
            if ($this->through) {
                // verify through is a belongs_to or has_many for access of keys
                if (!($through_relationship = $model::table()->get_relationship($this->through))) {
                    throw new HasManyThroughAssociationException("Could not find the association $this->through in model " . get_class($model));
                }

                if (!($through_relationship instanceof HasMany) && !($through_relationship instanceof BelongsTo)) {
                    throw new HasManyThroughAssociationException('has_many through can only use a belongs_to or has_many association');
                }

                $source = $this->resolve_source_relationship($through_relationship);

                if ($source instanceof HasMany && $through_relationship instanceof HasMany) {
                    // Reverse-FK chain (issue #22): the middle model has_many the
                    // target, so hop target.<source_fk> = middle.<source_pk> and
                    // filter by the through model's own owner FK on the middle
                    // table. The owner FK column (e.g. books.author_id) is named
                    // qualified in the key condition: the target table may have a
                    // column of the same name, which made it ambiguous.
                    $through_table = $through_relationship->get_table();
                    $this->options['joins'] = $this->construct_through_reverse_join_sql($through_table, $source);

                    $through_relationship->set_keys($model::table()->class->getName());
                    if (null === $through_relationship->primary_key) {
                        throw new RelationshipException("Could not determine primary key for relationship '{$this->attribute_name}'");
                    }
                    $owner_key = $through_relationship->foreign_key[0];
                    $this->foreign_key = [$owner_key];
                    $this->primary_key = $through_relationship->primary_key;
                    $this->qualified_keys = [$owner_key => $this->qualified_column($through_table, $owner_key)];
                } else {
                    // save old keys as we will be reseting them below for inner join convenience
                    $pk = $this->primary_key;
                    $fk = $this->foreign_key;

                    $this->set_keys($this->get_table()->class->getName(), true);

                    $through_table = $through_relationship->get_table();
                    $this->options['joins'] = $this->construct_inner_join_sql($through_table, true);

                    // reset keys
                    $this->primary_key = $pk;
                    $this->foreign_key = $fk;

                    // a key column of the middle table is named qualified: the target
                    // table may have a column of the same name (else left as it was)
                    foreach ($this->foreign_key as $key) {
                        if (self::table_has_column($through_table, $key)) {
                            $this->qualified_keys[$key] = $this->qualified_column($through_table, $key);
                        }
                    }
                }
            }

            $this->initialized = true;
        }

        // set_keys() above always assigns $this->primary_key; this is only null before
        // the first call.
        if (null === $this->primary_key) {
            throw new RelationshipException("Could not determine primary key for relationship '{$this->attribute_name}'");
        }

        // GH #40: a declared primary_key (the middle one on a reverse-FK through) is read
        // inflected, as the eager load and the builders read it; the table pk as before
        $value_keys = $this->primary_key === Table::load(get_class($model))->pk
            ? $this->primary_key
            : array_map(fn($key) => Inflector::instance()->variablize($key), $this->primary_key);

        if (!($conditions = $this->create_conditions_from_keys($model, $this->foreign_key, $value_keys))) {
            return null;
        }

        $options = $this->unset_non_finder_options($this->options);
        $options['conditions'] = $conditions;
        return $class_name::find($this->poly_relationship ? 'all' : 'first', $options);
    }

    /**
     * @param array<int|string, mixed> $attributes
     * @param-out array<int|string, mixed> $attributes
     * @return array<int|string, mixed>
     */
    private function inject_foreign_key_for_new_association(Model $model, array &$attributes): array
    {
        $this->set_keys(get_class($model));

        if ($this->keys_off_declared_primary_key()) {
            // GH #40: as in load(), each foreign key column takes the owner's value of the
            // corresponding declared primary_key column; a value passed in still wins.
            $inflector = Inflector::instance();

            foreach ($this->foreign_key as $i => $foreign_key) {
                if (!isset($this->declared_primary_key[$i])) {
                    break;
                }

                $foreign_key = $inflector->variablize($foreign_key);

                if (!isset($attributes[$foreign_key])) {
                    $attributes[$foreign_key] = $model->read_attribute($inflector->variablize($this->declared_primary_key[$i]));
                }
            }

            return $attributes;
        }

        $primary_key = Inflector::instance()->variablize($this->foreign_key[0]);

        if (!isset($attributes[$primary_key])) {
            $attributes[$primary_key] = $model->id;
        }

        return $attributes;
    }

    /**
     * GH #40: takes the foreign keys inject_foreign_key_for_new_association() added out of
     * $attributes when the associated model's attr_accessible / attr_protected would block them
     * (the check of Model::guarded_attribute_block(), which is private), and returns them for the
     * builders to assign directly, like Rails. An allowed one stays in the mass assignment, as
     * before; the attributes passed to the builder ($passed) stay guarded.
     *
     * @param array<int|string, mixed> $passed
     * @param array<int|string, mixed> $attributes
     * @param-out array<int|string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function take_out_guarded_foreign_keys(array $passed, array &$attributes): array
    {
        /** @var class-string<Model> $class_name */
        $class_name = $this->class_name;
        $direct = [];

        foreach ($this->foreign_key as $foreign_key) {
            $foreign_key = Inflector::instance()->variablize($foreign_key);

            if (isset($passed[$foreign_key]) || !array_key_exists($foreign_key, $attributes)) {
                continue; // passed in, or not injected
            }

            // exactly Model::guarded_attribute_block() on the new record (its attributes are the
            // table's columns): alias, then the 'id' shortcut of a pk-less table
            $name = $class_name::$alias_attribute[$foreign_key] ?? $foreign_key;
            if ('id' === $name && null === $class_name::table()->get_column_by_inflected_name('id')) {
                $name = $class_name::table()->pk[0] ?? '';
            }

            if ((!empty($class_name::$attr_accessible) && !in_array($name, $class_name::$attr_accessible))
                || (!empty($class_name::$attr_protected) && in_array($name, $class_name::$attr_protected))) {
                $direct[$foreign_key] = $attributes[$foreign_key];
                unset($attributes[$foreign_key]);
            }
        }

        return $direct;
    }

    /**
     * @param array<int|string, mixed> $attributes
     * @return Model
     */
    public function build_association(Model $model, $attributes = [])
    {
        $passed = $attributes;
        $attributes = $this->inject_foreign_key_for_new_association($model, $attributes);
        $direct = $this->take_out_guarded_foreign_keys($passed, $attributes);
        $record = parent::build_association($model, $attributes);

        foreach ($direct as $name => $value) {
            $record->$name = $value;
        }

        return $record;
    }

    /**
     * @param array<int|string, mixed> $attributes
     * @return Model
     */
    public function create_association(Model $model, $attributes = [])
    {
        $passed = $attributes;
        $attributes = $this->inject_foreign_key_for_new_association($model, $attributes);
        $direct = $this->take_out_guarded_foreign_keys($passed, $attributes);

        if ([] === $direct) {
            return parent::create_association($model, $attributes);
        }

        // as Model::create(), with the guarded foreign keys assigned before the save
        $class_name = $this->class_name;
        /** @var Model $record */
        $record = new $class_name($attributes);

        foreach ($direct as $name => $value) {
            $record->$name = $value;
        }

        $record->save();

        return $this->append_record_to_associate($model, $record);
    }

    /**
     * @param list<Model> $models
     * @param list<array<string, mixed>> $attributes
     * @param array<int|string, mixed> $includes
     * @return void
     */
    public function load_eagerly($models, $attributes, $includes, Table $table)
    {
        $this->set_keys($table->class->name);
        $owner_keys = $this->eager_owner_keys($table);

        if (null === $owner_keys) {
            $this->query_and_attach_related_models_eagerly($table, $models, $attributes, $includes, $this->foreign_key, $table->pk);

            return;
        }

        // GH #40: key off the declared primary_key, as load() does. load() finds nothing for an
        // owner whose key is null, so leave such an owner out of the query: its null would be
        // rendered as "fk IS NULL" and match it to every child that has no owner.
        $inflector = Inflector::instance();
        $keyed_models = $keyed_attributes = [];

        foreach ($models as $i => $model) {
            $key = array_map(fn($owner_key) => $attributes[$i][$inflector->variablize($owner_key)] ?? null, $owner_keys);

            if (all(null, $key)) {
                $model->set_relationship_from_eager_load(null, $this->attribute_name);
            } else {
                $keyed_models[] = $model;
                $keyed_attributes[] = $attributes[$i];
            }
        }

        if ([] !== $keyed_models) {
            $this->query_and_attach_related_models_eagerly($table, $keyed_models, $keyed_attributes, $includes, $this->foreign_key, $owner_keys);
        }
    }

    /**
     * GH #40: the owner columns the eager load keys off, as load() does: the declared
     * primary_key; on a reverse-FK `through` the middle relationship's declared primary_key;
     * a composite table pk for a composite foreign key. Null: the table pk, as before.
     *
     * @return list<string>|null
     */
    private function eager_owner_keys(Table $table): ?array
    {
        if (null !== $this->through) {
            $through = $table->get_relationship($this->through);
            $declared = $through instanceof HasMany && $this->resolve_source_relationship($through) instanceof HasMany
                ? $through->declared_primary_key
                : $this->declared_primary_key;

            return [] !== $declared ? $declared : null;
        }

        if ([] !== $this->declared_primary_key) {
            return $this->declared_primary_key;
        }

        return count($this->foreign_key) > 1 && count($table->pk) > 1 ? $table->pk : null;
    }
};

/**
 * One-to-one relationship.
 *
 * <code>
 * # Table name: states
 * # Primary key: id
 * class State extends ActiveRecord\Model {}
 *
 * # Table name: people
 * # Foreign key: state_id
 * class Person extends ActiveRecord\Model {
 *   static $has_one = array(array('state'));
 * }
 * </code>
 *
 * @package ActiveRecord
 * @see http://www.phpactiverecord.org/guides/associations
 */
class HasOne extends HasMany {};

/**
 * Not implemented — and deliberately loud about it: declaring
 * $has_and_belongs_to_many throws a RelationshipException as soon as the
 * model's table is loaded, instead of leaving a silently unusable
 * association behind. Model many-to-many with a has_many 'through'
 * relationship instead (see HasMany).
 *
 * @package ActiveRecord
 * @see HasMany
 */
class HasAndBelongsToMany extends AbstractRelationship
{
    /**
     * @param array<int|string, mixed> $options
     */
    public function __construct($options = [])
    {
        throw new RelationshipException("has_and_belongs_to_many is not implemented; use a has_many 'through' relationship instead.");
    }

    /**
     * Unreachable (the constructor always throws); kept only to satisfy the
     * abstract parent contract.
     *
     * @return null
     */
    public function load(Model $model)
    {
        return null;
    }

    /**
     * @param list<Model> $models
     * @param list<array<string, mixed>> $attributes
     * @param array<int|string, mixed> $includes
     * @return void
     */
    public function load_eagerly($models, $attributes, $includes, Table $table)
    {
        throw new RelationshipException('has_and_belongs_to_many eager loading is not implemented');
    }
};

/**
 * Belongs to relationship.
 *
 * <code>
 * class School extends ActiveRecord\Model {}
 *
 * class Person extends ActiveRecord\Model {
 *   static $belongs_to = array(
 *     array('school')
 *   );
 * }
 * </code>
 *
 * Example using options:
 *
 * <code>
 * class School extends ActiveRecord\Model {}
 *
 * class Person extends ActiveRecord\Model {
 *   static $belongs_to = array(
 *     array('school', 'foreign_key' => 'school_id')
 *   );
 * }
 * </code>
 *
 * @package ActiveRecord
 * @see valid_association_options
 * @see http://www.phpactiverecord.org/guides/associations
 */
class BelongsTo extends AbstractRelationship
{
    /** @var list<string>|null */
    private $primary_key_cache;

    public function __construct($options = [])
    {
        parent::__construct($options);

        if (!$this->class_name) {
            $this->set_inferred_class_name();
        }

        //infer from class_name
        if (!$this->foreign_key) {
            $this->foreign_key = [Inflector::instance()->keyify($this->class_name)];
        }
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        if ($name === 'primary_key') {
            return $this->primary_key_cache ??= [Table::load($this->class_name)->pk[0]];
        }

        return $this->$name;
    }

    public function load(Model $model)
    {
        $keys = [];
        $inflector = Inflector::instance();

        foreach ($this->foreign_key as $key) {
            $keys[] = $inflector->variablize($key);
        }

        if (!($conditions = $this->create_conditions_from_keys($model, $this->primary_key, $keys))) {
            return null;
        }

        $options = $this->unset_non_finder_options($this->options);
        $options['conditions'] = $conditions;
        $class = $this->class_name;
        return $class::first($options);
    }

    public function load_eagerly($models, $attributes, $includes, Table $table)
    {
        $this->query_and_attach_related_models_eagerly($table, $models, $attributes, $includes, $this->primary_key, $this->foreign_key);
    }

    // Unlike the other relationships, a belongs_to stores its foreign key on the associate (and not
    // on the new record). Therewfore, we must override the append_record_to_associate behaviour of
    // AbstractRelationship to provide this behaviour.
    protected function append_record_to_associate(Model $associate, Model $record)
    {
        $associate->{$this->foreign_key[0]} = $record->id;
        return $record;
    }
};
