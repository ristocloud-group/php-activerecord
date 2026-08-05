<?php

/**
 * @property int   $id
 * @property int   $order_id
 * @property int   $person_id
 * @property float $amount
 * @property-read Person $person
 * @property-read Order  $order
 */
class Payment extends ActiveRecord\Model
{
    // payment belongs to a person
    /** @var array<int, array<int|string, mixed>> */
    public static $belongs_to = [
        ['person'],
        ['order']];
}
