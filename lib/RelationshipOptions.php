<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Typed, normalized representation of a single relationship definition — one element of a
 * model's $has_many / $has_one / $belongs_to array.
 *
 * Built once per relationship per model class by {@see Table::set_associations()}, behind the
 * per-class Table cache; never constructed on a per-row or per-query hot path.
 *
 * `$class_name` is the *raw declared* class (from the `class` or `class_name` key) before any
 * namespace resolution — {@see AbstractRelationship::set_class_name()} still performs that.
 *
 * @package ActiveRecord
 */
final class RelationshipOptions
{
    /**
     * @param list<string> $foreign_key
     * @param list<string> $primary_key
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $class_name = null,
        public readonly array $foreign_key = [],
        public readonly array $primary_key = [],
        public readonly mixed $conditions = null,
        public readonly ?string $select = null,
        public readonly ?bool $readonly = null,
        public readonly ?string $namespace = null,
        public readonly ?string $order = null,
        public readonly ?string $group = null,
        public readonly ?string $having = null,
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
        public readonly ?string $through = null,
        public readonly ?string $source = null,
    ) {}

    /**
     * Build a validated, normalized instance from a raw definition array (as produced by
     * {@see wrap_strings_in_arrays()} in {@see Table::set_associations()}).
     *
     * @param array<int|string, mixed> $definition
     *
     * @throws RelationshipException when the relationship name (index 0) is missing/empty
     */
    public static function from_array(array $definition): self
    {
        $name = $definition[0] ?? null;
        if (!is_string($name) || '' === $name) {
            throw new RelationshipException('Relationship definition is missing its name (expected a non-empty string at index 0).');
        }

        $class = $definition['class'] ?? $definition['class_name'] ?? null;

        return new self(
            name: $name,
            class_name: is_string($class) ? $class : null,
            foreign_key: self::to_list($definition['foreign_key'] ?? null),
            primary_key: self::to_list($definition['primary_key'] ?? null),
            conditions: $definition['conditions'] ?? null,
            select: self::as_string($definition['select'] ?? null),
            readonly: self::as_bool($definition['readonly'] ?? null),
            namespace: self::as_string($definition['namespace'] ?? null),
            order: self::as_string($definition['order'] ?? null),
            group: self::as_string($definition['group'] ?? null),
            having: self::as_string($definition['having'] ?? null),
            limit: self::as_int($definition['limit'] ?? null),
            offset: self::as_int($definition['offset'] ?? null),
            through: self::as_string($definition['through'] ?? null),
            source: self::as_string($definition['source'] ?? null),
        );
    }

    private static function as_string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function as_int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function as_bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * Normalize a scalar or array option into a reindexed list of strings, mirroring the
     * relationship constructors' historical `is_array(...) ? array_values(...) : [scalar]`.
     * Non-scalar members of an array are dropped (already-malformed input).
     *
     * @return list<string>
     */
    private static function to_list(mixed $value): array
    {
        if (null === $value || '' === $value || [] === $value) {
            return [];
        }

        $items = is_array($value) ? array_values($value) : [$value];
        $out = [];
        foreach ($items as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
