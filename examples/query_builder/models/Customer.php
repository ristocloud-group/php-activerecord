<?php

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $country
 * @property-read array<int, Invoice> $invoices
 */
class Customer extends ActiveRecord\Model
{
    // joins('invoices') and left_joins('invoices') name this relationship
    public static $has_many = [['invoices']];
}
