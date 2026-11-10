<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Marks a protected static method of a model as a local scope, reachable by the method's name:
 * <code>
 * #[Scope]
 * protected static function published(Query $q): ?Query
 * {
 *     return $q->where('published', 1);
 * }
 * </code>
 * Post::published() and $query->published() then run it. A public static method named scope_published() is
 * a scope as well, without the attribute.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Scope {}
