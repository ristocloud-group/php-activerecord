<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

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
     * <li><b>constraints:</b> accepted and kept with the declaration for the application's own use
     * (e.g. its ON DELETE rules); the library never reads it and it never reaches the SQL (#194)</li>
     * </ul>
     *
     * @var list<string>
     */
    protected static $valid_association_options = ['primary_key', 'order', 'group', 'having', 'limit', 'offset', 'through', 'source', 'constraints'];

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
