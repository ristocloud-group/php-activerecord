<?php

/**
 * @property int    $id
 * @property int    $person_id
 * @property string $item_name
 * @property float  $price
 * @property float  $tax
 * @property-read Person               $person
 * @property-read array<int, Payment>  $payments
 * @property-read array<int, Person>   $people   has_many :through payments
 * @property-read \ActiveRecord\Errors $errors
 *
 * @method static array<int, Order> find_all_by_person_id(int $person_id)
 * @method Payment create_payments(array<string, mixed> $attributes) has_many builder
 */
class Order extends ActiveRecord\Model
{
    // order belongs to a person
    public static $belongs_to = [
        ['person']];

    // order can have many payments by many people
    // the conditions is just there as an example as it makes no logical sense
    public static $has_many = [
        ['payments'],
        ['people',
            'through'    => 'payments',
            'select'     => 'people.*, payments.amount',
            'conditions' => 'payments.amount < 200']];

    // order must have a price and tax > 0
    /** @var array<int, array<int|string, mixed>> */
    public static $validates_numericality_of = [
        ['price', 'greater_than' => 0],
        ['tax',   'greater_than' => 0]];

    // setup a callback to automatically apply a tax
    /** @var array<int, string> */
    public static $before_validation_on_create = ['apply_tax'];

    public function apply_tax(): void
    {
        if ($this->person->state == 'VA') {
            $tax = 0.045;
        } elseif ($this->person->state == 'CA') {
            $tax = 0.10;
        } else {
            $tax = 0.02;
        }

        $this->tax = $this->price * $tax;
    }
}
