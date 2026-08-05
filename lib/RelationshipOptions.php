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
        if (null !== $class && (!is_string($class) || '' === $class)) {
            throw new RelationshipException("Relationship '{$name}': 'class'/'class_name' must be a non-empty string.");
        }

        return new self(
            name: $name,
            class_name: $class,
            foreign_key: self::to_string_list($definition['foreign_key'] ?? null, $name, 'foreign_key'),
            primary_key: self::to_string_list($definition['primary_key'] ?? null, $name, 'primary_key'),
            conditions: $definition['conditions'] ?? null,
            select: self::as_string($definition['select'] ?? null),
            readonly: self::as_bool($definition['readonly'] ?? null),
            namespace: self::as_string($definition['namespace'] ?? null),
            order: self::as_string($definition['order'] ?? null),
            group: self::as_string($definition['group'] ?? null),
            having: self::as_string($definition['having'] ?? null),
            limit: self::as_int($definition['limit'] ?? null),
            offset: self::as_int($definition['offset'] ?? null),
            through: self::require_string_or_null($definition['through'] ?? null, $name, 'through'),
            source: self::require_string_or_null($definition['source'] ?? null, $name, 'source'),
        );
    }

    /**
     * Lenient coercion for pass-through finder metadata (select/order/group/having/namespace):
     * these are never consumed as typed control-flow values, only forwarded to the SQL finder,
     * so a type mismatch degrades to null rather than throwing.
     */
    private static function as_string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Lenient coercion for pass-through finder metadata (limit/offset) — see {@see as_string}.
     */
    private static function as_int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * Lenient coercion for pass-through finder metadata (readonly) — see {@see as_string}.
     */
    private static function as_bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * Strict validation for a declaration option consumed as a typed control-flow value
     * (through/source): must be a non-empty string when present, absent stays null.
     *
     * @throws RelationshipException when present but not a non-empty string
     */
    private static function require_string_or_null(mixed $value, string $name, string $option): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value) {
            throw new RelationshipException("Relationship '{$name}': '{$option}' must be a non-empty string.");
        }

        return $value;
    }

    /**
     * Strict normalization of a declaration option consumed as a typed control-flow value
     * (foreign_key/primary_key) into a reindexed list of strings, mirroring the relationship
     * constructors' historical `is_array(...) ? array_values(...) : [scalar]`. Absent/null/[]
     * is treated as "not declared" and yields []; a present-but-malformed element throws.
     *
     * @return list<string>
     *
     * @throws RelationshipException when a present element is not a non-empty string
     */
    private static function to_string_list(mixed $value, string $name, string $option): array
    {
        if (null === $value || [] === $value) {
            return [];
        }

        $items = is_array($value) ? array_values($value) : [$value];
        $out = [];
        foreach ($items as $item) {
            if (!is_string($item) || '' === $item) {
                throw new RelationshipException("Relationship '{$name}': '{$option}' must be a non-empty string or a list of non-empty strings.");
            }
            $out[] = $item;
        }

        return $out;
    }
}
