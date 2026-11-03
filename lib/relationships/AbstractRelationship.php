<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

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
    protected array $qualified_keys = [];

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
     * take] for the matching. A has_one takes the one child at its offset (a null or negative
     * one is 0), none with a limit of 0, as load()'s find('first') does (#38). A negative limit
     * or offset of a has_many stays on the query, as before.
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
            // the window of Model::find('first'): one record at the offset, none for limit 0
            [$skip, $take] = [max(0, $skip), 0 === $take ? 0 : 1];
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
     * The keys of a hash are read as the associated model's finders read them
     * (#144, spec §6.2.7), the associated model being the one the load finds
     * (for a `through`, the target): its $alias_attribute keys and its
     * attribute names name their columns (#92), through
     * {@see Table::map_names()}. An alias next to the column it stands for
     * keeps both conditions (#129): each hash map_names() returns is rendered
     * alike, and they are ANDed with their binds in order. Any other key, a
     * qualified one included, renders as it always did.
     *
     * A `through` load joins the middle table, which may have a column of the
     * same name: there a key that map_names() changed is named with the
     * target's table, as finders with joins name a model column (see
     * qualify_changed_names()). A key it left as declared is not.
     *
     * @param array<int|string, mixed> $conditions
     * @return array<int|string, mixed>
     */
    protected function to_positional_conditions(Connection $conn, array $conditions)
    {
        if (is_hash($conditions)) {
            require_once __DIR__ . '/../Expressions.php';
            /** @var class-string<Model> $class */
            $class = $this->class_name;
            $aliases = $class::$alias_attribute;
            $keys = array_keys($conditions);
            $hashes = $this->get_table()->map_names($conditions, $aliases);
            $fragments = $values = [];

            if (!empty($this->options['through'])) {
                $hashes = $this->qualify_changed_names($conn, $keys, $hashes);
            }

            foreach ($hashes as $hash) {
                $expressions = new Expressions($conn, $hash);
                $fragments[] = $expressions->to_s();
                $values[] = $expressions->values();
            }

            return array_merge([implode(' AND ', $fragments)], array_flatten($values));
        }

        return $conditions;
    }

    /**
     * The hashes map_names() made of the declared keys $keys, with each name it
     * changed named with the target's table, as the statement names that table
     * (`venues`.`city`). map_names() keeps every condition, in order, so the
     * n-th name of the hashes stands for the n-th declared key. A name that
     * comes to equal another of its hash starts a new hash, as in map_names():
     * every condition is kept.
     *
     * @param list<int|string> $keys
     * @param list<array<int|string, mixed>> $hashes
     * @return list<array<int|string, mixed>>
     */
    private function qualify_changed_names(Connection $conn, array $keys, array $hashes): array
    {
        $table = $this->get_table()->get_fully_qualified_table_name();
        $qualified = [];
        $i = 0;

        foreach ($hashes as $hash) {
            $ret = [];

            foreach ($hash as $name => $value) {
                if (is_string($name) && $name !== $keys[$i]) {
                    $name = $table . '.' . $conn->quote_name($name);
                }

                ++$i;

                if (array_key_exists($name, $ret)) {
                    $qualified[] = $ret;
                    $ret = [];
                }

                $ret[$name] = $value;
            }

            $qualified[] = $ret;
        }

        return $qualified;
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
     * @see construct_join_sql()
     */
    public function construct_inner_join_sql(Table $from_table, $using_through = false, $alias = null)
    {
        // the flag read by its truth, as this method always read it
        return $this->construct_join_sql($from_table, (bool) $using_through, $alias, 'INNER');
    }

    /**
     * The JOIN of the association, of the type $type: INNER, as construct_inner_join_sql() writes it, or LEFT,
     * the same JOIN with only its keyword changed. The ON compares the key columns, which the relationship
     * names, and the joined table gets $alias when one is given.
     *
     * @param Table $from_table the table used for the FROM SQL statement
     * @param bool $using_through is this a THROUGH relationship?
     * @param string|null $alias a table alias for when a table is being joined twice
     * @param string $type the type of the join: INNER or LEFT
     * @return string SQL JOIN fragment
     * @throws RelationshipException for another type
     */
    public function construct_join_sql(Table $from_table, bool $using_through = false, ?string $alias = null, string $type = 'INNER'): string
    {
        if ('INNER' !== $type && 'LEFT' !== $type) {
            throw new RelationshipException("Unknown join type '$type' for relationship '{$this->attribute_name}': INNER or LEFT");
        }

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

        return "$type JOIN $join_table_name {$alias}ON($from_table_name.$foreign_key = $aliased_join_table_name.$join_primary_key)";
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
     * key itself, unless a target column is fetched under the same name (PDO lower-cases
     * fetched names on every adapter, so "Owner_Ref" collides with owner_ref): the alias
     * would overwrite it on every target row, so a private alias is used for the matching.
     * The private alias stays within 63 bytes, Postgres' identifier limit.
     */
    protected function middle_key_alias(string $key): string
    {
        foreach (array_keys($this->get_table()->columns) as $name) {
            if (0 === strcasecmp((string) $name, $key)) {
                $alias = "ar_through_$key";

                return strlen($alias) > 63 ? 'ar_through_' . md5($key) : $alias;
            }
        }

        return $key;
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
