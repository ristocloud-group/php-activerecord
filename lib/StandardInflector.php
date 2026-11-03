<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * @package ActiveRecord
 */
class StandardInflector extends Inflector
{
    /**
     * @param string|null $s
     * @return string
     */
    public function tableize($s)
    {
        $s ??= "";
        return Utils::pluralize(strtolower($this->underscorify($s)));
    }

    /**
     * @param string|null $s
     * @return string
     */
    public function variablize($s)
    {
        $s ??= "";
        return str_replace(['-',' '], ['_','_'], strtolower(trim($s)));
    }
}
