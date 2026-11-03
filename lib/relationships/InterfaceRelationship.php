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
