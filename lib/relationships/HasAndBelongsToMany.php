<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

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
