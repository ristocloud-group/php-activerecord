<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

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
class HasOne extends HasMany
{
    /**
     * Valid options to use for a {@link HasOne} relationship, on top of the base ones of {@link AbstractRelationship}:
     * those of {@link HasMany}, which it took through HasMany before declaring its own list.
     *
     * @var list<string>
     */
    protected static $valid_association_options = ['primary_key', 'order', 'group', 'having', 'limit', 'offset', 'through', 'source', 'unscoped'];
};
