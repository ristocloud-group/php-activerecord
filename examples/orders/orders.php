<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Person.php';
require_once __DIR__ . '/models/Order.php';
require_once __DIR__ . '/models/Payment.php';

// Create a throwaway SQLite database and load the schema, so this runs with no
// database server to configure.
$db = __DIR__ . '/orders.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/orders.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

// create some people
$jax = new Person(['name' => 'Jax', 'state' => 'CA']);
$jax->save();

// compact way to create and save a model
/** @var Person $tito */
$tito = Person::create(['name' => 'Tito', 'state' => 'VA']);

// place orders. tax is automatically applied in a callback
// create_orders will automatically place the created model into $tito->orders
// even if it failed validation
$pokemon = $tito->create_orders(['item_name' => 'Live Pokemon', 'price' => 6999.99]);
$coal    = $tito->create_orders(['item_name' => 'Lump of Coal', 'price' => 100.00]);
$freebie = $tito->create_orders(['item_name' => 'Freebie', 'price' => -100.99]);

if ($freebie->errors->size() > 0) {
    echo "[FAILED] saving order $freebie->item_name: " . join(', ', $freebie->errors->full_messages()) . "\n\n";
}

// payments
$pokemon->create_payments(['amount' => 1.99, 'person_id' => $tito->id]);
$pokemon->create_payments(['amount' => 4999.50, 'person_id' => $tito->id]);
$pokemon->create_payments(['amount' => 2.50, 'person_id' => $jax->id]);

// reload since we don't want the freebie to show up (because it failed validation)
$tito->reload();

$tito_orders = $tito->orders;
echo "$tito->name has " . count($tito_orders) . " orders for: " . join(', ', ActiveRecord\collect($tito_orders, 'item_name')) . "\n\n";

// get all orders placed by Tito
foreach (Order::find_all_by_person_id($tito->id) as $order) {
    echo "Order #$order->id for $order->item_name ($$order->price + $$order->tax tax) ordered by " . $order->person->name . "\n";

    if (count($order->payments) > 0) {
        // display each payment for this order
        foreach ($order->payments as $payment) {
            echo "  payment #$payment->id of $$payment->amount by " . $payment->person->name . "\n";
        }
    } else {
        echo "  no payments\n";
    }

    echo "\n";
}

// display summary of all payments made by Tito and Jax
$conditions = [
    'conditions'	=> ['id IN(?)',[$tito->id,$jax->id]],
    'order'			=> 'name desc'];

/** @var array<int, Person> $people */
$people = Person::all($conditions);
foreach ($people as $person) {
    $person_payments = $person->payments;
    $n = count($person_payments);
    $total = array_sum(ActiveRecord\collect($person_payments, 'amount'));
    echo "$person->name made $n payments for a total of $$total\n\n";
}

// using order has_many people through payments with options
// array('people', 'through' => 'payments', 'select' => 'people.*, payments.amount', 'conditions' => 'payments.amount < 200'));
// this means our people in the loop below also has the payment information since it is part of an inner join
// we will only see 2 of the people instead of 3 because 1 of the payments is greater than 200
/** @var Order $order */
$order = Order::find($pokemon->id);
echo "Order #$order->id for $order->item_name ($$order->price + $$order->tax tax)\n";

foreach ($order->people as $person) {
    echo "  payment of $$person->amount by " . $person->name . "\n";
}
