<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Thrown when a query, a transaction start, a commit or a rollback is tried on a connection that streams a cursor
 * ({@link Query::cursor()} with stream: true): iterate the cursor to its end, or unset() it, first.
 *
 * @package ActiveRecord
 */
class ConnectionBusy extends ActiveRecordException {};
