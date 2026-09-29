<?php

namespace Examples\Serialization;

/**
 * A namespaced model; its table name comes from the short class name (reviews).
 *
 * @property int $id
 * @property int $product_id
 * @property int $stars
 */
class Review extends \ActiveRecord\Model {}
