<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * CSV serializer.
 *
 * @package ActiveRecord
 */
class CsvSerializer extends Serialization
{
    /**
     * @var string
     */
    public static $delimiter = ',';

    /**
     * @var string
     */
    public static $enclosure = '"';

    /**
     * @return string
     */
    public function to_s()
    {
        if (@$this->options['only_header'] == true) {
            return $this->header();
        }
        return $this->row();
    }

    /**
     * @return string
     */
    private function header()
    {
        return $this->to_csv(array_keys($this->to_a()));
    }

    /**
     * @return string
     */
    private function row()
    {
        return $this->to_csv($this->to_a());
    }

    /**
     * @param array<int|string, mixed> $arr
     * @return string
     */
    private function to_csv($arr)
    {
        $outstream = fopen('php://temp', 'w');
        if (false === $outstream) {
            throw new ActiveRecordException('Could not open a temporary stream for CSV serialization');
        }
        fputcsv($outstream, $arr, self::$delimiter, self::$enclosure, '\\');
        rewind($outstream);
        $buffer = trim(stream_get_contents($outstream));
        fclose($outstream);
        return $buffer;
    }
}
