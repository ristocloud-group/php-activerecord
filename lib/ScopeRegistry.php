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

    /** @var array<class-string, array<string, \Closure>> the global-scope indexes built so far, by model class */
    private static array $globals = [];

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
                throw self::scope_error($model_class, $method->getName(), 'is marked #[Scope] but is not a protected static method');
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
                throw self::scope_error($model_class, $method->getName(), 'is marked #[Scope] but is not a protected static method');
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
                throw self::scope_error($model_class, $name, $reason);
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
     * The global scopes of $model_class, name => invoker, the root ancestor's first, then each subclass down to the
     * class; within a class its own methods in declaration order, then those of its traits in use order. A redeclared
     * method keeps the position of the first declaration. Built by reflection on the first call and kept; a build that
     * throws is not kept. It does not build the registry of local scopes: those are validated on their own first use.
     *
     * @internal Serves Table, Model and Query; not a supported API.
     * @param class-string $model_class
     * @return array<string, \Closure>
     * @throws ConfigException when a global scope is declared wrongly (spec §6, errors 1 to 5)
     */
    public static function global_scopes(string $model_class): array
    {
        return self::$globals[$model_class] ??= self::build_global_scopes($model_class);
    }

    /**
     * Whether $model_class declares or inherits a global scope.
     *
     * @internal Serves Table, Model and Query; not a supported API.
     * @param class-string $model_class
     * @throws ConfigException when a global scope is declared wrongly
     */
    public static function has_global_scopes(string $model_class): bool
    {
        return [] !== self::global_scopes($model_class);
    }

    /**
     * @param class-string $model_class
     * @return array<string, \Closure>
     */
    private static function build_global_scopes(string $model_class): array
    {
        $reflection = new \ReflectionClass($model_class);

        // the classes below Model, root first
        $chain = [];
        for ($class = $reflection; false !== $class && Model::class !== $class->getName(); $class = $class->getParentClass()) {
            array_unshift($chain, $class);
        }

        /** @var array<string, string> $placed lower-case name => name, in index order */
        $placed = [];
        /** @var array<string, \ReflectionMethod> $methods name => method, seen from $model_class */
        $methods = [];

        foreach ($chain as $class) {
            foreach (self::methods_declared_by($class) as $declared) {
                $name = $declared->getName();
                $lower = strtolower($name);
                $is_global = [] !== $declared->getAttributes(GlobalScope::class);

                if (!$is_global) {
                    if (isset($placed[$lower])) {
                        throw self::scope_error($model_class, $name, 'redeclares a global scope without #[GlobalScope]');
                    }

                    continue;
                }

                if (!$declared->isStatic() || !$declared->isProtected()) {
                    throw self::scope_error($model_class, $name, 'is marked #[GlobalScope] but is not a protected static method');
                }

                if ([] !== $declared->getAttributes(Scope::class)) {
                    throw self::scope_error($model_class, $name, 'is marked both #[Scope] and #[GlobalScope]');
                }

                foreach (array_slice($declared->getParameters(), 1) as $parameter) {
                    if (!$parameter->isOptional()) {
                        throw self::scope_error($model_class, $name, 'takes a required parameter besides the query');
                    }
                }

                if (!isset($placed[$lower])) {
                    $placed[$lower] = $name;
                    $methods[$name] = $reflection->getMethod($name);
                }
            }
        }

        if ([] === $placed) {
            return [];
        }

        $reasons = self::global_name_reasons($reflection, $placed);
        $index = [];
        foreach ($placed as $name) {
            if (null !== ($reason = $reasons[strtolower($name)] ?? null)) {
                throw self::scope_error($model_class, $name, $reason);
            }

            $index[$name] = $methods[$name]->getClosure(null);
        }

        return $index;
    }

    /**
     * The methods $class declares itself, in declaration order, then those its traits bring, in use order.
     *
     * @param \ReflectionClass<object> $class
     * @return list<\ReflectionMethod>
     */
    private static function methods_declared_by(\ReflectionClass $class): array
    {
        $own = [];
        $own_names = [];
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class->getName() && self::is_written_in($method, $class)) {
                $own[] = $method;
                $own_names[strtolower($method->getName())] = true;
            }
        }

        foreach ($class->getTraits() as $trait) {
            foreach ($trait->getMethods() as $trait_method) {
                if (!isset($own_names[strtolower($trait_method->getName())])) {
                    $own_names[strtolower($trait_method->getName())] = true;
                    $own[] = $class->getMethod($trait_method->getName());
                }
            }
        }

        return $own;
    }

    /**
     * Whether the body of $method is in the source of $class itself and not in a trait the class uses.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function is_written_in(\ReflectionMethod $method, \ReflectionClass $class): bool
    {
        return $method->getFileName() === $class->getFileName()
            && $method->getStartLine() >= $class->getStartLine()
            && $method->getEndLine() <= $class->getEndLine();
    }

    /**
     * Why each global scope name is refused, by lower-case name: it is also a local scope of the model, or breaks the
     * name rules of local scopes, with the reason texts of those.
     *
     * @param \ReflectionClass<object> $reflection
     * @param array<string, string> $placed
     * @return array<string, string>
     */
    private static function global_name_reasons(\ReflectionClass $reflection, array $placed): array
    {
        // the local scopes, read as the registry reads them
        $locals = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            $name = $method->getName();

            if ([] !== $method->getAttributes(Scope::class)) {
                $locals[strtolower($name)] = true;
            } elseif ($method->isPublic() && str_starts_with($name, 'scope_') && strlen($name) > 6) {
                $locals[strtolower(substr($name, 6))] = true;
            }
        }

        $query_methods = [];
        foreach ((new \ReflectionClass(Query::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), '__')) {
                $query_methods[strtolower($method->getName())] = true;
            }
        }

        $reasons = [];
        foreach ($placed as $lower => $name) {
            $reason = match (true) {
                isset($locals[$lower]) => 'is also a local scope of the model',
                isset($query_methods[$lower]) => 'is a public method of Query',
                self::starts_a_dynamic_finder($lower) => 'starts like a dynamic finder',
                'update' === $lower || 'delete' === $lower => 'is update or delete, refused by a model without conditions',
                default => null,
            };

            if (null !== $reason) {
                $reasons[$lower] = $reason;
            }
        }

        return $reasons;
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

    private static function starts_a_dynamic_finder(string $name): bool
    {
        foreach (self::DYNAMIC_FINDER_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function scope_error(string $model_class, string $name, string $reason): ConfigException
    {
        return new ConfigException(sprintf('%s declares the scope %s(), which %s', $model_class, $name, $reason));
    }
}
