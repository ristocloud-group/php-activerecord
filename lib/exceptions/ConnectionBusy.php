<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Thrown when a query, a transaction start, a commit or a second stream is tried on a connection that streams a cursor
 * ({@link Query::cursor()} with stream: true; a rollback closes the stream instead: see Connection::rollback()):
 * iterate the cursor to its end, or unset() it, first.
 *
 * @package ActiveRecord
 */
class ConnectionBusy extends ActiveRecordException {};
