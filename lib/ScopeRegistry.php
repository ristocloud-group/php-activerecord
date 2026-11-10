<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * The local scopes of one model class: its public static scope_x() methods and its protected static methods carrying
 * the Scope attribute, found by reflection and validated when the registry is built.
 *
 * @internal Serves Table, Model and Query; not a supported API.
 * @phpstan-import-type Where from QueryState
 * @phpstan-import-type WhereGroup from QueryState
 */
final class ScopeRegistry
{
    /**
     * The prefixes of the dynamic finders of Model::__callStatic(); a scope name may not start with one.
     */
    public const array DYNAMIC_FINDER_PREFIXES = ['find_by', 'find_all_by', 'count_by', 'find_or_create_by'];

    /** @var array<class-string, self> the registries built so far, by model class; a failed build is not kept */
    private static array $registries = [];

    /** @var array<string, string> public scope name => name of the method that implements it */
    private array $scopes = [];

    /**
     * Reflects the scopes of $model_class and validates them. The names are compared as PHP compares method names,
     * without regard to case: a scope named like a method in another case would never be reached.
     *
     * @param class-string $model_class
     * @throws ConfigException when a scope collides with another name or a #[Scope] method is not protected static
     */
    public function __construct(private readonly string $model_class)
    {
        $reflection = new \ReflectionClass($model_class);
        $statics = $reflection->getMethods(\ReflectionMethod::IS_STATIC);

        /** @var array<string, list<string>> $declared public scope name => the methods that declare it */
        $declared = [];
        /** @var array<string, true> $scope_methods lower-case name of each method that declares a scope */
        $scope_methods = [];

        foreach ($statics as $method) {
            $has_attribute = [] !== $method->getAttributes(Scope::class);

            if ($has_attribute && !$method->isProtected()) {
                throw $this->error($model_class, $method->getName(), 'is marked #[Scope] but is not a protected static method');
            }

            if ($has_attribute) {
                $declared[$method->getName()][] = $method->getName();
                $scope_methods[strtolower($method->getName())] = true;
            } elseif ($method->isPublic() && str_starts_with($method->getName(), 'scope_') && strlen($method->getName()) > 6) {
                $declared[substr($method->getName(), 6)][] = $method->getName();
                $scope_methods[strtolower($method->getName())] = true;
            }
        }

        $query_methods = [];
        foreach ((new \ReflectionClass(Query::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), '__')) {
                $query_methods[strtolower($method->getName())] = true;
            }
        }

        // the static methods of the model and its ancestors, of any visibility, and their public instance methods
        // (reload(), save(), ...), which PHP calls itself, the scope methods aside
        $static_names = [];
        $instance_names = [];
        foreach ($reflection->getMethods() as $method) {
            $lower = strtolower($method->getName());

            if (!$method->isStatic() && [] !== $method->getAttributes(Scope::class)) {
                throw $this->error($model_class, $method->getName(), 'is marked #[Scope] but is not a protected static method');
            }

            if (isset($scope_methods[$lower])) {
                continue;
            }

            if ($method->isStatic()) {
                $static_names[$lower] = true;
            } elseif ($method->isPublic()) {
                $instance_names[$lower] = true;
            }
        }

        foreach ($declared as $name => $methods) {
            $lower = strtolower($name);
            $reason = match (true) {
                count($methods) > 1 => 'is declared twice (' . implode('() and ', $methods) . '())',
                isset($query_methods[$lower]) => 'is a public method of Query',
                isset($static_names[$lower]) => 'is a static method of the model or of a class it extends',
                isset($instance_names[$lower]) => 'is a public method of the model or of a class it extends',
                isset($scope_methods[$lower]) && strtolower($methods[0]) !== $lower => 'is the method of another scope',
                $this->starts_a_dynamic_finder($lower) => 'starts like a dynamic finder',
                'update' === $lower || 'delete' === $lower => 'is update or delete, refused by a model without conditions',
                default => null,
            };

            if (null !== $reason) {
                throw $this->error($model_class, $name, $reason);
            }

            $this->scopes[$name] = $methods[0];
        }
    }

    /**
     * The registry of $model_class, built on the first call and kept. It is built by reflection only, so it never
     * touches the database. A build that throws is not kept: it throws again on the next call.
     *
     * @internal Serves Table, Model and Query; not a supported API.
     * @param class-string $model_class
     * @throws ConfigException when a scope collides with another name or a #[Scope] method is not protected static
     */
    public static function for_class(string $model_class): self
    {
        return self::$registries[$model_class] ??= new self($model_class);
    }

    /**
     * Whether the model declares a scope reachable by the public name $name.
     */
    public function has(string $name): bool
    {
        return isset($this->scopes[$name]);
    }

    /**
     * Runs the scope $name of the model on $query with $args and groups the conditions it added: they join the query
     * as one parenthesized AND group, so an OR inside the scope cannot escape into the conditions around it. When the
     * conditions the query held before hold an OR between them, they are grouped too, so that the scope filters every
     * row (Eloquent does the same): where(a)->or_where(b) and then a scope is (a OR b) AND (...). The scope runs on an
     * emptied list of conditions, and the two are put back together even when it throws. The flags a where call sets
     * when it adds nothing stay on the query's state. A scope returns null or the query it was given.
     *
     * @template TModel of Model
     * @param Query<TModel> $query
     * @param array<mixed> $args
     * @return Query<TModel>
     * @throws ActiveRecordException when the scope returns anything but null or $query
     */
    public function apply(Query $query, string $name, array $args): Query
    {
        $state = $query->state();
        $earlier = $state->wheres;
        $state->wheres = [];

        try {
            $result = (new \ReflectionMethod($this->model_class, $this->scopes[$name]))->invoke(null, $query, ...$args);

            if (null !== $result && $result !== $query) {
                throw new ActiveRecordException(sprintf(
                    'The scope %s() of %s must return null or the query it was given, %s returned',
                    $name,
                    $this->model_class,
                    get_debug_type($result),
                ));
            }
        } finally {
            // read again through the query: the scope changed its state
            $added = $query->state()->wheres;

            // a scope that adds no condition leaves the query's own as they were
            $state->wheres = [] === $added ? $earlier : [
                ...(self::has_top_level_or($earlier) ? [self::group($earlier)] : $earlier),
                self::group($added),
            ];
        }

        return $query;
    }

    /**
     * Whether a condition of $wheres after the first is joined to the one before it by OR (the boolean of the first
     * is dropped).
     *
     * @param list<Where> $wheres
     */
    private static function has_top_level_or(array $wheres): bool
    {
        foreach (array_slice($wheres, 1) as $where) {
            if ('OR' === $where['boolean']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The conditions $wheres as one AND group, the shape of a closure group.
     *
     * @param list<Where> $wheres
     * @return WhereGroup
     */
    private static function group(array $wheres): array
    {
        $group = new QueryState();
        $group->wheres = $wheres;

        return ['type' => 'group', 'boolean' => 'AND', 'not' => false, 'group' => $group];
    }

    private function starts_a_dynamic_finder(string $name): bool
    {
        foreach (self::DYNAMIC_FINDER_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function error(string $model_class, string $name, string $reason): ConfigException
    {
        return new ConfigException(sprintf('%s declares the scope %s(), which %s', $model_class, $name, $reason));
    }
}
