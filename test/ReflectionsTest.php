<?php

use ActiveRecord\Reflections;

class ReflectionsTestDummy
{
    public $whatever;
}

class ReflectionsTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function test_add_then_get_by_class_name()
    {
        $reflection = Reflections::instance()->add(ReflectionsTestDummy::class)->get(ReflectionsTestDummy::class);
        $this->assert_true($reflection instanceof ReflectionClass);
        $this->assert_equals(ReflectionsTestDummy::class, $reflection->getName());
    }

    public function test_add_is_idempotent()
    {
        $reflections = Reflections::instance();
        $first = $reflections->add(ReflectionsTestDummy::class)->get(ReflectionsTestDummy::class);
        $second = $reflections->add(ReflectionsTestDummy::class)->get(ReflectionsTestDummy::class);
        $this->assert_same($first, $second);
    }

    public function test_add_accepts_an_object()
    {
        $reflection = Reflections::instance()->add(new ReflectionsTestDummy())->get(ReflectionsTestDummy::class);
        $this->assert_equals(ReflectionsTestDummy::class, $reflection->getName());
    }

    public function test_add_and_get_with_no_argument_resolve_the_calling_class()
    {
        // With no explicit class, Reflections::get_class() falls through to
        // Singleton::get_called_class(), which resolves to the object the
        // method chain was invoked on (the Reflections singleton itself).
        $reflection = Reflections::instance()->add()->get();
        $this->assert_equals(Reflections::class, $reflection->getName());
    }

    public function test_get_throws_when_class_was_never_reflected()
    {
        $this->expectException(ActiveRecord\ActiveRecordException::class);
        $this->expectExceptionMessage('Class not found: NoSuchClassEverReflected');
        Reflections::instance()->get('NoSuchClassEverReflected');
    }

    public function test_destroy_uncaches_the_reflection()
    {
        $reflections = Reflections::instance();
        $reflections->add(ReflectionsTestDummy::class);
        $reflections->destroy(ReflectionsTestDummy::class);

        $this->expectException(ActiveRecord\ActiveRecordException::class);
        $reflections->get(ReflectionsTestDummy::class);
    }
}
