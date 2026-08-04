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
        $this->attribute_name = $options[0];
        $this->options = $this->merge_association_options($options);

        $relationship = strtolower(denamespace(get_called_class()));

        if ($relationship === 'hasmany' || $relationship === 'hasandbelongstomany') {
            $this->poly_relationship = true;
        }

        if (isset($this->options['conditions']) && !is_array($this->options['conditions'])) {
            $this->options['conditions'] = [$this->options['conditions']];
        }

        if (isset($this->options['class'])) {
            $this->set_class_name($this->options['class']);
        } elseif (isset($this->options['class_name'])) {
            $this->set_class_name($this->options['class_name']);
        }

        $this->attribute_name = strtolower(Inflector::instance()->variablize($this->attribute_name));

        if (!$this->foreign_key && isset($this->options['foreign_key'])) {
            $this->foreign_key = is_array($this->options['foreign_key']) ? $this->options['foreign_key'] : [$this->options['foreign_key']];
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
     * @param list<mixed> $includes array of eager load directives
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

        foreach ($attributes as $column => $value) {
            $values[] = $value[$inflector->variablize($model_values_key)];
        }

        $values = [$values];
        $conditions = SQLBuilder::create_conditions_from_underscored_string($table->conn, $query_key, $values);

        if (isset($options['conditions']) && strlen($options['conditions'][0]) > 1) {
            Utils::add_condition($options['conditions'], $conditions);
        } else {
            $options['conditions'] = $conditions;
        }

        if (!empty($includes)) {
            $options['include'] = $includes;
        }

        if (!empty($options['through'])) {
            // save old keys as we will be reseting them below for inner join convenience
            $pk = $this->primary_key;
            $fk = $this->foreign_key;

            $this->set_keys($this->get_table()->class->getName(), true);

            $through_relationship = $table->get_relationship($options['through']);
            $through_table = $through_relationship->get_table();

            $options['joins'] = $this->construct_inner_join_sql($through_table, true);

            $query_key = null;

            // reset keys
            $this->primary_key = $pk;
            $this->foreign_key = $fk;
        }

        $options = $this->unset_non_finder_options($options);

        $class = $this->class_name;

        $related_models = $class::find('all', $options);
        $used_models = [];
        $model_values_key = $inflector->variablize($model_values_key);
        $query_key = $inflector->variablize($query_key);

        foreach ($models as $model) {
            $matches = 0;
            $key_to_match = $model->$model_values_key;

            foreach ($related_models as $related) {
                if (empty($query_key) || $related->$query_key == $key_to_match) {
                    $hash = spl_object_hash($related);

                    if (in_array($hash, $used_models)) {
                        $model->set_relationship_from_eager_load(clone($related), $this->attribute_name);
                    } else {
                        $model->set_relationship_from_eager_load($related, $this->attribute_name);
                    }

                    $used_models[] = $hash;
                    $matches++;
                }
            }

            if (0 === $matches) {
                $model->set_relationship_from_eager_load(null, $this->attribute_name);
            }
        }
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
        return new $class_name($attributes);
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
        $available_options = array_merge(self::$valid_association_options, static::$valid_association_options);
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
     * @param list<string> $condition_keys
     * @param list<string> $value_keys
     * @return array<int, mixed>|null
     */
    protected function create_conditions_from_keys(Model $model, $condition_keys = [], $value_keys = [])
    {
        $condition_string = implode('_and_', $condition_keys);
        $condition_values = array_values($model->get_values_for($value_keys));

        // return null if all the foreign key values are null so that we don't try to do a query like "id is null"
        if (all(null, $condition_values)) {
            return null;
        }

        $conditions = SQLBuilder::create_conditions_from_underscored_string(Table::load(get_class($model))->conn, $condition_string, $condition_values);

        # DO NOT CHANGE THE NEXT TWO LINES. add_condition operates on a reference and will screw options array up
        if (isset($this->options['conditions'])) {
            $options_conditions = $this->options['conditions'];
        } else {
            $options_conditions = [];
        }

        return Utils::add_condition($options_conditions, $conditions);
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
            $aliased_join_table_name = $alias = $this->get_table()->conn->quote_name($alias);
            $alias .= ' ';
        } else {
            $aliased_join_table_name = $join_table_name;
        }

        return "INNER JOIN $join_table_name {$alias}ON($from_table_name.$foreign_key = $aliased_join_table_name.$join_primary_key)";
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
     * @param list<mixed> $includes The nested includes to eager load on the associated models
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

        if (isset($this->options['through'])) {
            $this->through = $this->options['through'];

            if (isset($this->options['source'])) {
                $this->set_class_name($this->options['source']);
            }
        }

        if (!$this->primary_key && isset($this->options['primary_key'])) {
            $this->primary_key = is_array($this->options['primary_key']) ? $this->options['primary_key'] : [$this->options['primary_key']];
        }

        if (!$this->class_name) {
            $this->set_inferred_class_name();
        }
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

                // save old keys as we will be reseting them below for inner join convenience
                $pk = $this->primary_key;
                $fk = $this->foreign_key;

                $this->set_keys($this->get_table()->class->getName(), true);

                $relation = $model::table()->get_relationship($this->through);
                $through_table = $relation->get_table();
                $this->options['joins'] = $this->construct_inner_join_sql($through_table, true);

                // reset keys
                $this->primary_key = $pk;
                $this->foreign_key = $fk;
            }

            $this->initialized = true;
        }

        if (!($conditions = $this->create_conditions_from_keys($model, $this->foreign_key, $this->primary_key))) {
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
        $primary_key = Inflector::instance()->variablize($this->foreign_key[0]);

        if (!isset($attributes[$primary_key])) {
            $attributes[$primary_key] = $model->id;
        }

        return $attributes;
    }

    /**
     * @param array<int|string, mixed> $attributes
     * @return Model
     */
    public function build_association(Model $model, $attributes = [])
    {
        $attributes = $this->inject_foreign_key_for_new_association($model, $attributes);
        return parent::build_association($model, $attributes);
    }

    /**
     * @param array<int|string, mixed> $attributes
     * @return Model
     */
    public function create_association(Model $model, $attributes = [])
    {
        $attributes = $this->inject_foreign_key_for_new_association($model, $attributes);
        return parent::create_association($model, $attributes);
    }

    /**
     * @param list<Model> $models
     * @param list<array<string, mixed>> $attributes
     * @param list<mixed> $includes
     * @return void
     */
    public function load_eagerly($models, $attributes, $includes, Table $table)
    {
        $this->set_keys($table->class->name);
        $this->query_and_attach_related_models_eagerly($table, $models, $attributes, $includes, $this->foreign_key, $table->pk);
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
 * @todo implement me
 * @package ActiveRecord
 * @see http://www.phpactiverecord.org/guides/associations
 */
class HasAndBelongsToMany extends AbstractRelationship
{
    /**
     * @param array<int|string, mixed> $options
     */
    public function __construct($options = [])
    {
        /* options =>
         *   join_table - name of the join table if not in lexical order
         *   foreign_key -
         *   association_foreign_key - default is {assoc_class}_id
         *   uniq - if true duplicate assoc objects will be ignored
         *   validate
         */
    }

    /**
     * Unimplemented stub (see class-level @todo); always returns null.
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
     * @param list<mixed> $includes
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
 *     array('school', 'primary_key' => 'school_id')
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
