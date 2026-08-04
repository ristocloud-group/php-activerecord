<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

use XmlWriter;

/**
 * Base class for Model serializers.
 *
 * All serializers support the following options:
 *
 * <ul>
 * <li><b>only:</b> a string or array of attributes to be included.</li>
 * <li><b>except:</b> a string or array of attributes to be excluded.</li>
 * <li><b>methods:</b> a string or array of methods to invoke. The method's name will be used as a key for the final attributes array
 * along with the method's returned value</li>
 * <li><b>include:</b> a string or array of associated models to include in the final serialized product.</li>
 * <li><b>only_method:</b> a method that's called and only the resulting array is serialized
 * <li><b>skip_instruct:</b> set to true to skip the <?xml ...?> declaration.</li>
 * </ul>
 *
 * Example usage:
 *
 * <code>
 * # include the attributes id and name
 * # run $model->encoded_description() and include its return value
 * # include the comments association
 * # include posts association with its own options (nested)
 * $model->to_json(array(
 *   'only' => array('id','name', 'encoded_description'),
 *   'methods' => array('encoded_description'),
 *   'include' => array('comments', 'posts' => array('only' => 'id'))
 * ));
 *
 * # except the password field from being included
 * $model->to_xml(array('except' => 'password')));
 * </code>
 *
 * @package ActiveRecord
 * @link http://www.phpactiverecord.org/guides/utilities#topic-serialization
 */
abstract class Serialization
{
    /**
     * @var Model
     */
    protected $model;

    /**
     * @var array<string, mixed>
     */
    protected $options;

    /**
     * @var array<string, mixed>
     */
    protected $attributes;

    /**
     * The default format to serialize DateTime objects to.
     *
     * @see DateTime
     * @var string
     */
    public static $DATETIME_FORMAT = 'iso8601';

    /**
     * Set this to true if the serializer needs to create a nested array keyed
     * on the name of the included classes such as for xml serialization.
     *
     * Setting this to true will produce the following attributes array when
     * the include option was used:
     *
     * <code>
     * $user = array('id' => 1, 'name' => 'Tito',
     *   'permissions' => array(
     *     'permission' => array(
     *       array('id' => 100, 'name' => 'admin'),
     *       array('id' => 101, 'name' => 'normal')
     *     )
     *   )
     * );
     * </code>
     *
     * Setting to false will produce this:
     *
     * <code>
     * $user = array('id' => 1, 'name' => 'Tito',
     *   'permissions' => array(
     *     array('id' => 100, 'name' => 'admin'),
     *     array('id' => 101, 'name' => 'normal')
     *   )
     * );
     * </code>
     *
     * @var boolean
     */
    protected $includes_with_class_name_element = false;

    /**
     * Constructs a {@link Serialization} object.
     *
     * @param Model $model The model to serialize
     * @param array<string, mixed> &$options Options for serialization
     * @return Serialization
     */
    public function __construct(Model $model, &$options)
    {
        $this->model = $model;
        $this->options = $options;
        $this->attributes = $model->attributes();
        $this->parse_options();
    }

    private function parse_options(): void
    {
        $this->check_only();
        $this->check_except();
        $this->check_methods();
        $this->check_include();
        $this->check_only_method();
    }

    private function check_only(): void
    {
        if (isset($this->options['only'])) {
            $this->options_to_a('only');

            $exclude = array_diff(array_keys($this->attributes), $this->options['only']);
            $this->attributes = array_diff_key($this->attributes, array_flip($exclude));
        }
    }

    private function check_except(): void
    {
        if (isset($this->options['except']) && !isset($this->options['only'])) {
            $this->options_to_a('except');
            $this->attributes = array_diff_key($this->attributes, array_flip($this->options['except']));
        }
    }

    private function check_methods(): void
    {
        if (isset($this->options['methods'])) {
            $this->options_to_a('methods');

            foreach ($this->options['methods'] as $method) {
                if (method_exists($this->model, $method)) {
                    $this->attributes[$method] = $this->model->$method();
                }
            }
        }
    }

    private function check_only_method(): void
    {
        if (isset($this->options['only_method'])) {
            $method = $this->options['only_method'];
            if (method_exists($this->model, $method)) {
                $this->attributes = $this->model->$method();
            }
        }
    }

    private function check_include(): void
    {
        if (isset($this->options['include'])) {
            $this->options_to_a('include');

            $serializer_class = get_class($this);

            foreach ($this->options['include'] as $association => $options) {
                if (!is_array($options)) {
                    $association = $options;
                    $options = [];
                }

                try {
                    $assoc = $this->model->$association;

                    if (!is_array($assoc)) {
                        $serialized = new $serializer_class($assoc, $options);
                        $this->attributes[$association] = $serialized->to_a();
                        ;
                    } else {
                        $includes = [];

                        foreach ($assoc as $a) {
                            $serialized = new $serializer_class($a, $options);

                            if ($this->includes_with_class_name_element) {
                                $includes[strtolower(get_class($a))][] = $serialized->to_a();
                            } else {
                                $includes[] = $serialized->to_a();
                            }
                        }

                        $this->attributes[$association] = $includes;
                    }

                } catch (UndefinedPropertyException $e) {
                    ;//move along
                }
            }
        }
    }

    /**
     * @param string $key
     * @return void
     */
    final protected function options_to_a($key)
    {
        if (!is_array($this->options[$key])) {
            $this->options[$key] = [$this->options[$key]];
        }
    }

    /**
     * Returns the attributes array.
     * @return array<string, mixed>
     */
    final public function to_a()
    {
        foreach ($this->attributes as &$value) {
            if ($value instanceof \DateTime) {
                $value = $value->format(self::$DATETIME_FORMAT);
            }
        }
        return $this->attributes;
    }

    /**
     * Returns the serialized object as a string.
     * @see to_s
     * @return string
     */
    final public function __toString()
    {
        return $this->to_s();
    }

    /**
     * Performs the serialization.
     * @return string|array<string, mixed>
     */
    abstract public function to_s();
};

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
        parent::$include_root = self::$include_root;
        $json = json_encode(parent::to_s());
        if ($json === false) {
            throw new ActiveRecordException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }
}

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
        $this->writer->startElement(strtolower(denamespace(($this->model))));
        $this->write($this->to_a());
        $this->writer->endElement();
        $this->writer->endDocument();
        $xml = $this->writer->outputMemory(true);

        if (@$this->options['skip_instruct'] == true) {
            $xml = preg_replace('/<\?xml version.*?\?>/', '', $xml);
        }

        return $xml;
    }

    /**
     * @param array<int|string, mixed>|object $data
     * @param int|string|null $tag
     */
    private function write($data, $tag = null): void
    {
        foreach ($data as $attr => $value) {
            if ($tag != null) {
                $attr = $tag;
            }

            if (is_array($value) || is_object($value)) {
                if (!is_int(key($value))) {
                    $this->writer->startElement($attr);
                    $this->write($value);
                    $this->writer->endElement();
                } else {
                    $this->write($value, $attr);
                }

                continue;
            }

            $this->writer->writeElement($attr, $value);
        }
    }
}

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
        fputcsv($outstream, $arr, self::$delimiter, self::$enclosure, '\\');
        rewind($outstream);
        $buffer = trim(stream_get_contents($outstream));
        fclose($outstream);
        return $buffer;
    }
}
