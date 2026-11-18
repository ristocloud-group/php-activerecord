<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Marks a protected static method of a model as a global scope: its conditions are added to every query of the model.
 * <code>
 * #[GlobalScope]
 * protected static function real(Query $q): ?Query
 * {
 *     return $q->where('dummy', 0);
 * }
 * </code>
 * The method takes the query as its only required parameter and returns null or that query. Its name is the scope's
 * name. A subclass that redeclares the method must repeat the attribute.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class GlobalScope {}
