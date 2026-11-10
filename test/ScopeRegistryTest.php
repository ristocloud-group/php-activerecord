<?php

use ActiveRecord\ConfigException;

/**
 * Discovery and validation of local scopes (spec §3.1, §3.2, §5): scope_x and #[Scope] methods,
 * has_scope(), the per-class registry, and the collisions that throw at its first build.
 */
class ScopeRegistryTest extends DatabaseTest
{
    public function test_has_scope_on_model_and_query(): void
    {
        $this->assert_true(ScopedAuthor::has_scope('tito_or_parent'));
        $this->assert_true(ScopedAuthor::has_scope('named'));
        $this->assert_false(ScopedAuthor::has_scope('scope_tito_or_parent'));
        $this->assert_false(ScopedAuthor::has_scope('nope'));
        $this->assert_false(ScopedAuthor::has_scope('instance_only'));
        $this->assert_true(ScopedAuthor::new_query()->has_scope('named'));
        $this->assert_false(ScopedAuthor::new_query()->has_scope('nope'));
        // a scope is looked up by its name as written
        $this->assert_false(ScopedAuthor::has_scope('Named'));
    }

    public function test_registry_is_built_once(): void
    {
        $this->assert_same(ScopedAuthor::table()->scopes(), ScopedAuthor::table()->scopes());
    }

    public function test_scopes_are_inherited(): void
    {
        $this->assert_true(ScopedAuthorChild::has_scope('named'));
        $this->assert_true(ScopedAuthorChild::has_scope('tito_or_parent'));
        $this->assert_not_same(ScopedAuthor::table()->scopes(), ScopedAuthorChild::table()->scopes());
    }

    public function test_collisions_throw_config_exception(): void
    {
        $cases = [
            'CollidingQueryMethodScope' => ['where', 'Query'],
            'CollidingModelStaticScope' => ['create', 'static'],
            'CollidingInheritedStaticScope' => ['helper', 'static'],
            'CollidingFinderPrefixScope' => ['find_by_title', 'dynamic finder'],
            'CollidingWriteScope' => ['delete'],
            'CollidingDoubleScope' => ['twice', 'declared twice'],
            'CollidingHasScopeScope' => ['has_scope'],
            // R8: PHP's method names are case-insensitive, so the checks are too
            'CollidingCaseScope' => ['Where()', 'Query'],
            // R8: every public method of the model, static or not, and the methods of the other scopes
            'CollidingInstanceMethodScope' => ['reload()', 'public method of the model'],
            'CollidingScopeMethodScope' => ['scope_x()', 'another scope'],
        ];

        foreach ($cases as $class => $words) {
            try {
                $class::has_scope('x');
                $this->fail("$class did not throw");
            } catch (ConfigException $e) {
                $this->assert_string_contains_string($class, $e->getMessage());
                foreach ($words as $word) {
                    $this->assert_string_contains_string($word, $e->getMessage());
                }
            }
        }
    }

    public function test_collision_surfaces_on_any_first_interaction(): void
    {
        $this->expectException(ConfigException::class);
        CollidingValidAndBadScope::has_scope('fine');
    }

    public function test_attribute_scope_must_be_protected_static(): void
    {
        foreach (['PublicAttributeScope', 'PrivateAttributeScope', 'InstanceAttributeScope'] as $class) {
            try {
                $class::has_scope('x');
                $this->fail("$class did not throw");
            } catch (ConfigException $e) {
                $this->assert_string_contains_string('protected static', $e->getMessage());
            }
        }
    }
}
