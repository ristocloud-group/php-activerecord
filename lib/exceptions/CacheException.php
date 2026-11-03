<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Thrown by {@link Cache} adapters (e.g. Memcache, Redis) for connection or
 * dependency problems.
 *
 * @package ActiveRecord
 */
class CacheException extends ActiveRecordException {};
