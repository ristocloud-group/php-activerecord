<?php

/**
 * An Author with a static method of its own that a builder method is also named after, as a consumer's
 * model can have: PHP calls the real static and __callStatic() never sees the call, so the static
 * forwarding cannot take over a name that a model class defines (QueryForwardingTest).
 */
class ShadowingAuthor extends Author
{
    public static $table_name = 'authors';

    public static function latest(): string
    {
        return 'own';
    }
}
