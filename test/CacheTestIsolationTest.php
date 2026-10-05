<?php

use ActiveRecord\Cache;

/**
 * Sorts right after CacheTest in suite order: guards that CacheTest does not
 * leave a cache adapter enabled for the rest of the suite (#139).
 */
class CacheTestIsolationTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function test_previous_cache_test_left_no_adapter_enabled()
    {
        $this->assert_null(Cache::$adapter);
    }
}
