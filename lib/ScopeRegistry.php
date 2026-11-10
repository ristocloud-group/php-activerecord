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
     * Reflects the scopes of $model_class and validates them.
     *
     * @param class-string $model_class
     * @throws ConfigException when a scope collides with another name or a #[Scope] method is not protected static
     */
    public function __construct(private readonly string $model_class)
    {
        $reflection = new \ReflectionClass($model_class);
        $statics = $reflection->getMethods(\ReflectionMethod::IS_STATIC);

        /** @var array<string, list<string>> $declared */
        $declared = [];
        /** @var array<string, true> $scope_methods */
        $scope_methods = [];

        foreach ($statics as $method) {
            $has_attribute = [] !== $method->getAttributes(Scope::class);

            if ($has_attribute && !$method->isProtected()) {
                throw $this->error($model_class, $method->getName(), 'is marked #[Scope], which requires a protected static method');
            }

            if ($has_attribute) {
                $declared[$method->getName()][] = $method->getName() . '()';
                $scope_methods[$method->getName()] = true;
            } elseif ($method->isPublic() && str_starts_with($method->getName(), 'scope_') && strlen($method->getName()) > 6) {
                $declared[substr($method->getName(), 6)][] = $method->getName() . '()';
                $scope_methods[$method->getName()] = true;
            }
        }

        foreach ($reflection->getMethods() as $method) {
            if (!$method->isStatic() && [] !== $method->getAttributes(Scope::class)) {
                throw $this->error($model_class, $method->getName(), 'is marked #[Scope], which requires a protected static method');
            }
        }

        $query_methods = [];
        foreach ((new \ReflectionClass(Query::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), '__')) {
                $query_methods[$method->getName()] = true;
            }
        }

        $static_names = [];
        foreach ($statics as $method) {
            if (!isset($scope_methods[$method->getName()])) {
                $static_names[$method->getName()] = true;
            }
        }

        foreach ($declared as $name => $methods) {
            $reason = match (true) {
                count($methods) > 1 => 'is declared twice (' . implode(' and ', $methods) . ')',
                isset($query_methods[$name]) => 'is a public method of Query',
                isset($static_names[$name]) => 'is a static method of the model or of a class it extends',
                $this->starts_a_dynamic_finder($name) => 'starts like a dynamic finder',
                'update' === $name || 'delete' === $name => 'is update or delete, which a model refuses to run without conditions',
                default => null,
            };

            if (null !== $reason) {
                throw $this->error($model_class, $name, $reason);
            }

            $this->scopes[$name] = isset($scope_methods[$name]) ? $name : 'scope_' . $name;
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
     * as one parenthesized AND group, so an OR inside the scope cannot escape into the conditions around it. A scope
     * returns null or the query it was given.
     *
     * @template TModel of Model
     * @param Query<TModel> $query
     * @param list<mixed> $args
     * @return Query<TModel>
     * @throws ActiveRecordException when the scope returns anything but null or $query
     */
    public function apply(Query $query, string $name, array $args): Query
    {
        $state = $query->state();
        $before = count($state->wheres);

        $result = (new \ReflectionMethod($this->model_class, $this->scopes[$name]))->invoke(null, $query, ...$args);

        if (null !== $result && $result !== $query) {
            throw new ActiveRecordException(sprintf(
                'The scope %s() of %s must return null or the query it was given, %s returned',
                $name,
                $this->model_class,
                get_debug_type($result),
            ));
        }

        if (count($state->wheres) > $before) {
            $group = new QueryState();
            $group->wheres = array_slice($state->wheres, $before);
            $state->wheres = [
                ...array_slice($state->wheres, 0, $before),
                ['type' => 'group', 'boolean' => 'AND', 'not' => false, 'group' => $group],
            ];
        }

        return $query;
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
