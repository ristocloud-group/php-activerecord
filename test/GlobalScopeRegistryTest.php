<?php

use ActiveRecord\ConfigException;
use ActiveRecord\ScopeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The global-scope index of ScopeRegistry (spec §3.1, §6 errors 1-5): discovery, order, validation.
 */
class GlobalScopeRegistryTest extends DatabaseTest
{
    public function test_index_order_root_first_redeclared_keeps_position(): void
    {
        $this->assert_same(['not_bush', 'named'], array_keys(ScopeRegistry::global_scopes(GlobalScopedAuthorChild::class)));
        $this->assert_same(['not_bush', 'own', 'not_tito'], array_keys(ScopeRegistry::global_scopes(GlobalScopedTraitAuthor::class)));
    }

    public function test_model_without_global_scopes(): void
    {
        $this->assert_same([], ScopeRegistry::global_scopes(Author::class));
        $this->assert_false(ScopeRegistry::has_global_scopes(Author::class));
        $this->assert_true(ScopeRegistry::has_global_scopes(GlobalScopedAuthor::class));
    }

    public function test_invokers_are_closures_and_cached(): void
    {
        $scopes = ScopeRegistry::global_scopes(GlobalScopedAuthor::class);
        $this->assert_true($scopes['not_bush'] instanceof Closure);
        $this->assert_same($scopes, ScopeRegistry::global_scopes(GlobalScopedAuthor::class));
    }

    #[DataProvider('invalid_declarations')]
    public function test_invalid_declaration_throws(string $class, string $reason): void
    {
        $this->assert_exception_message_contains('declares the scope', fn() => ScopeRegistry::global_scopes($class), ConfigException::class);
        $this->assert_exception_message_contains($reason, fn() => ScopeRegistry::global_scopes($class), ConfigException::class);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalid_declarations(): array
    {
        return [
            'not protected static' => [GlobalScopePublic::class, 'is marked #[GlobalScope] but is not a protected static method'],
            'both attributes' => [GlobalScopeBothAttributes::class, 'is marked both #[Scope] and #[GlobalScope]'],
            'like a local scope' => [GlobalScopeLikeLocal::class, 'is also a local scope of the model'],
            'like a Query method' => [GlobalScopeQueryMethod::class, 'GlobalScopeQueryMethod declares the scope latest(), which is a public method of Query'],
            'like a dynamic finder' => [GlobalScopeFinderPrefix::class, 'GlobalScopeFinderPrefix declares the scope find_by_hidden(), which starts like a dynamic finder'],
            // Query has public update() and delete(): its rule comes first (a static delete() cannot be declared at all,
            // Model's delete() is an instance method)
            'update' => [GlobalScopeUpdate::class, 'GlobalScopeUpdate declares the scope update(), which is a public method of Query'],
            'required parameter' => [GlobalScopeRequiredParam::class, 'takes a required parameter besides the query'],
            'redeclared without' => [GlobalScopeRedeclaredWithout::class, 'redeclares a global scope without #[GlobalScope]'],
        ];
    }

    public function test_global_index_does_not_validate_local_scopes(): void
    {
        // CollidingQueryMethodScope (3b fixture) has an invalid local scope and no global one
        $this->assert_same([], ScopeRegistry::global_scopes(CollidingQueryMethodScope::class));
    }
}
