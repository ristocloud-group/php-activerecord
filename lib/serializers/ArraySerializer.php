<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Array serializer.
 *
 * @package ActiveRecord
 */
class ArraySerializer extends Serialization
{
    /**
     * @var bool
     */
    public static $include_root = false;

    /**
     * @return array<string, mixed>|string
     */
    public function to_s()
    {
        return self::$include_root ? [strtolower(get_class($this->model)) => $this->to_a()] : $this->to_a();
    }
}
