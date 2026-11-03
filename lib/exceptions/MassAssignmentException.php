<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Thrown by {@link Model} mass assignment, when strict mass assignment is on
 * ({@link Config::set_strict_mass_assignment()}), for attributes blocked by
 * attr_accessible or attr_protected.
 *
 * @package ActiveRecord
 */
class MassAssignmentException extends ModelException {};
