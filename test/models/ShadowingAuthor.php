<?php

/**
 * An Author with a static latest() of its own, the name of a builder method (Query::latest()), as a consumer's
 * model can have a static named like one. Author::latest() is forwarded to a new query, but here PHP calls the
 * real static of the class and __callStatic() never sees the call, so the static forwarding cannot take over a
 * name that a model class defines (QueryForwardingTest::test_a_subclass_method_shadows_a_forwarded_one).
 */
class ShadowingAuthor extends Author
{
    public static $table_name = 'authors';

    public static function latest(): string
    {
        return 'own';
    }
}
