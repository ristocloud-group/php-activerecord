<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * JSON serializer.
 *
 * @package ActiveRecord
 */
class JsonSerializer extends ArraySerializer
{
    /**
     * @var bool
     */
    public static $include_root = false;

    /**
     * @return string
     */
    public function to_s()
    {
        // Root on JsonSerializer's own flag without writing it into
        // ArraySerializer::$include_root, which later to_array() calls read.
        $data = self::$include_root ? [strtolower(get_class($this->model)) => $this->to_a()] : $this->to_a();
        $json = json_encode($data);
        if ($json === false) {
            throw new ActiveRecordException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }
}
