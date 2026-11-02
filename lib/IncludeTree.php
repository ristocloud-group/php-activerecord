<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The relationships a query eager loads, as QueryState::$includes holds them (in the form the legacy 'include'
 * option takes them), and the rules by which Query::with(), without() and with_only() read their arguments and
 * change them: a relationship named by dots, or in a list in which a name may hold a list of its own, merged in the
 * order it was first given, and taken off by its path.
 *
 * @internal Serves Query; not a supported API.
 * @package ActiveRecord
 */
final class IncludeTree
{
    /**
     * @param string $model_class The model the relationships are of, as the messages of the exceptions name it
     */
    public function __construct(private readonly string $model_class) {}

    /**
     * The path of every relationship that the arguments of with(), without() or with_only() ($method) name: each a
     * dotted name, or a list in which an entry is a dotted name, or a name with a list of its own (see
     * listed_paths()). A path is the names from the model's relationship to the one it names, in order.
     *
     * @param array<string|array<mixed>> $relations
     * @return list<list<string>>
     * @throws ActiveRecordException for an empty name, or a list in no form that is read
     */
    public function relationship_paths(string $method, array $relations): array
    {
        $paths = [];

        foreach ($relations as $relation) {
            $paths = [...$paths, ...(is_array($relation) ? $this->listed_paths($method, $relation, []) : [$this->dotted_path($method, $relation)])];
        }

        return $paths;
    }

    /**
     * The paths of a list of relationships as the legacy 'include' option takes it, under the path $prefix: an
     * entry with an int key is a name, and one with a string key is a name with a list of the relationships loaded
     * under it. A name with an empty list is loaded alone.
     *
     * @param array<mixed> $list
     * @param list<string> $prefix
     * @return list<list<string>>
     * @throws ActiveRecordException for an empty name, or an entry in no such form
     */
    private function listed_paths(string $method, array $list, array $prefix): array
    {
        $paths = [];

        foreach ($list as $key => $entry) {
            if (is_int($key) && is_string($entry)) {
                $paths[] = [...$prefix, ...$this->dotted_path($method, $entry)];
            } elseif (is_string($key) && is_array($entry)) {
                $path = [...$prefix, ...$this->dotted_path($method, $key)];
                $paths = [...$paths, ...([] === $entry ? [$path] : $this->listed_paths($method, $entry, $path))];
            } else {
                throw new ActiveRecordException(sprintf(
                    '%s() takes a relationship name, or a list of them in which a name may hold a list, not %s at key %s, on %s',
                    $method,
                    get_debug_type($entry),
                    var_export($key, true),
                    $this->model_class,
                ));
            }
        }

        return $paths;
    }

    /**
     * The names of a dotted relationship name ('author.books'), in order.
     *
     * @return list<string>
     * @throws ActiveRecordException for a name that is empty, or has an empty part
     */
    private function dotted_path(string $method, string $name): array
    {
        $path = explode('.', $name);

        foreach ($path as $part) {
            if ('' === trim($part)) {
                throw new ActiveRecordException(sprintf("Empty relationship name: '%s' in %s() on %s", $name, $method, $this->model_class));
            }
        }

        return $path;
    }

    /**
     * $tree, the relationships a query eager loads, with each of $paths in it. A relationship is a list entry
     * while nothing is loaded under it, and a key with the list of what is once something is, where it was first
     * given; each is in the tree once.
     *
     * @param array<int|string, mixed> $tree
     * @param list<list<string>> $paths
     * @return array<int|string, mixed>
     */
    public static function including(array $tree, array $paths): array
    {
        foreach ($paths as $path) {
            $tree = self::include_path($tree, $path);
        }

        return $tree;
    }

    /**
     * $tree with the relationship that $path names in it, and those before it in the path as its parents.
     *
     * @param array<int|string, mixed> $tree
     * @param list<string> $path
     * @return array<int|string, mixed>
     */
    private static function include_path(array $tree, array $path): array
    {
        $name = $path[0];
        $rest = array_slice($path, 1);
        $included = [];
        $found = false;

        foreach ($tree as $key => $entry) {
            if (!self::is_include($key, $entry, $name)) {
                self::add_include($included, $key, $entry);
            } elseif ([] === $rest) {
                // it is loaded already, with whatever is loaded under it
                self::add_include($included, $key, $entry);
                $found = true;
            } else {
                // a name that is loaded alone becomes the parent of the others, in its place
                $included[$name] = self::include_path(is_array($entry) ? $entry : [], $rest);
                $found = true;
            }
        }

        if (!$found) {
            if ([] === $rest) {
                $included[] = $name;
            } else {
                $included[$name] = self::include_path([], $rest);
            }
        }

        return $included;
    }

    /**
     * $tree without the relationship that $path names, and what is loaded under it. A relationship that loses all
     * that was loaded under it stays in the tree, with an empty list.
     *
     * @param array<int|string, mixed> $tree
     * @param list<string> $path
     * @return array<int|string, mixed>
     */
    public static function excluding(array $tree, array $path): array
    {
        $name = $path[0];
        $rest = array_slice($path, 1);
        $included = [];

        foreach ($tree as $key => $entry) {
            if (!self::is_include($key, $entry, $name)) {
                self::add_include($included, $key, $entry);
            } elseif ([] !== $rest) {
                // what is under it: a relationship loaded alone has none, so nothing is taken off
                self::add_include($included, $key, is_array($entry) ? self::excluding($entry, $rest) : $entry);
            }
        }

        return $included;
    }

    /**
     * Whether an entry of an include tree ($key and $entry as a foreach gives them) is the relationship $name: a
     * key that is a name, or an entry that is.
     */
    private static function is_include(int|string $key, mixed $entry, string $name): bool
    {
        return is_string($key) ? $key === $name : $entry === $name;
    }

    /**
     * Adds an entry of an include tree to $included, as it was: a name with its list keeps its key, and a name
     * alone is a list entry, which takes the next place of the list.
     *
     * @param array<int|string, mixed> $included
     */
    private static function add_include(array &$included, int|string $key, mixed $entry): void
    {
        if (is_string($key)) {
            $included[$key] = $entry;
        } else {
            $included[] = $entry;
        }
    }
}
