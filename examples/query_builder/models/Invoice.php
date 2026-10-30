<?php

/**
 * @property int                         $id
 * @property int                         $customer_id
 * @property string                      $number
 * @property string                      $status
 * @property int                         $total_cents
 * @property ActiveRecord\DateTime       $issued_on
 * @property ActiveRecord\DateTime|null  $paid_on
 * @property string|null                 $note
 * @property ActiveRecord\DateTime|null  $created_at
 * @property ActiveRecord\DateTime|null  $updated_at
 * @property-read Customer               $customer
 *
 * Attributes that exist only when a query selects them under that name (see query_builder.php):
 * @property int                         $cents          select('total_cents as cents')
 * @property string|null                 $customer_name  select('customers.name as customer_name')
 * @property int|string                  $total          select_raw('SUM(total_cents) AS total')
 */
class Invoice extends ActiveRecord\Model
{
    public static $belongs_to = [['customer']];

    // where('amount', ...), order_by('amount'), update(['amount' => ...]) ... all name total_cents
    public static $alias_attribute = ['amount' => 'total_cents'];
}
