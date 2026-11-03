<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

use XmlWriter;

/**
 * XML serializer.
 *
 * @package ActiveRecord
 */
class XmlSerializer extends Serialization
{
    private ?XmlWriter $writer = null;

    /**
     * @param array<string, mixed> &$options
     */
    public function __construct(Model $model, &$options)
    {
        $this->includes_with_class_name_element = true;
        parent::__construct($model, $options);
    }

    /**
     * Asserts that the writer has been initialized (it is set at the start
     * of {@see xml_encode()}, before any of the write() helpers run).
     */
    private function writer(): XmlWriter
    {
        if (null === $this->writer) {
            throw new ActiveRecordException('XmlSerializer writer accessed before initialization');
        }

        return $this->writer;
    }

    /**
     * @return string
     */
    public function to_s()
    {
        return $this->xml_encode();
    }

    /**
     * @return string
     */
    private function xml_encode()
    {
        $this->writer = new XmlWriter();
        $this->writer->openMemory();
        $this->writer->startDocument('1.0', 'UTF-8');
        $this->writer()->startElement(strtolower(denamespace(($this->model))));
        $this->write($this->to_a());
        $this->writer()->endElement();
        $this->writer()->endDocument();
        $xml = $this->writer()->outputMemory(true);

        if (@$this->options['skip_instruct'] == true) {
            $xml = preg_replace('/<\?xml version.*?\?>/', '', $xml) ?? $xml;
        }

        return $xml;
    }

    /**
     * @param array<int|string, mixed>|object $data
     * @param int|string|null $tag
     */
    private function write($data, $tag = null): void
    {
        foreach ((array) $data as $attr => $value) {
            if ($tag != null) {
                $attr = $tag;
            }

            if (is_array($value) || is_object($value)) {
                if (!is_int(key($value))) {
                    $this->writer()->startElement((string) $attr);
                    $this->write($value);
                    $this->writer()->endElement();
                } else {
                    $this->write($value, $attr);
                }

                continue;
            }

            $this->writer()->writeElement((string) $attr, $value);
        }
    }
}
