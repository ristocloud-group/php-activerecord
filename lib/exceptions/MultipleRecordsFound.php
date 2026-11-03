<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Thrown when a query finds more than the one record it wants: by {@link Query::sole()} and
 * {@link Query::sole_value()}.
 *
 * @package ActiveRecord
 */
class MultipleRecordsFound extends ActiveRecordException {};
