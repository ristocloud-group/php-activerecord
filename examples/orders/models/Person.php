<?php

/**
 * @property int    $id
 * @property string $name
 * @property string $state
 * @property-read array<int, Order>   $orders
 * @property-read array<int, Payment> $payments
 * @property-read \ActiveRecord\Errors $errors
 * @property-read float $amount injected by Order::$people's `select => 'people.*, payments.amount'`
 *
 * @method Order create_orders(array<string, mixed> $attributes) has_many builder
 */
class Person extends ActiveRecord\Model
{
    // a person can have many orders and payments
    /** @var array<int, array<int|string, mixed>> */
    public static $has_many = [
        ['orders'],
        ['payments']];

    // must have a name and a state
    /** @var array<int, array<int|string, mixed>> */
    public static $validates_presence_of = [
        ['name'], ['state']];
}
