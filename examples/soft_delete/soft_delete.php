<?php

// Soft delete on the read side: a SoftDeletable model hides its trashed rows from every query, and
// with_trashed() / only_trashed() choose which rows a query reads.

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/models/Order.php';

// Create a throwaway SQLite database and load the schema, so this runs with no
// database server to configure.
$db = __DIR__ . '/soft_delete.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/soft_delete.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

/** @param array<int, ActiveRecord\Model> $orders */
function numbers(array $orders): string
{
    return [] === $orders ? '(none)' : implode(', ', array_map(fn(ActiveRecord\Model $order): string => (string) $order->read_attribute('number'), $orders));
}

function show(string $label, string $value): void
{
    echo str_pad($label . ':', 36) . $value . "\n";
}

// 1. The default filter: the trashed orders (2 and 4) are hidden, on the builder and on the legacy finders alike.
show('live orders (builder)', numbers(Order::order_by('id')->get()));
show('live orders (all())', numbers(Order::all(['order' => 'id'])));
show('count()', (string) Order::count());
show('first() of ORD-002', var_export(Order::where('number', 'ORD-002')->first(), true));
show('SQL', Order::where('number', 'ORD-001')->to_sql());

// 2. with_trashed(): the trashed rows with the live ones.
show('with_trashed()', numbers(Order::with_trashed()->order_by('id')->get()));

// 3. only_trashed(): the trashed rows only. The filter is replaced, never ANDed with the default one.
show('only_trashed()', numbers(Order::only_trashed()->order_by('id')->get()));
show('only_trashed() SQL', Order::only_trashed()->to_sql());

// 4. The last call wins: without_trashed() brings the default filter back.
show('only_trashed()->without_trashed()', numbers(Order::only_trashed()->without_trashed()->order_by('id')->get()));

// 5. trashed() tells a trashed record from a live one.
$trashed = Order::with_trashed()->where('number', 'ORD-002')->first();
$live = Order::where('number', 'ORD-001')->first();
show('ORD-002 trashed()', var_export($trashed?->trashed(), true));
show('ORD-001 trashed()', var_export($live?->trashed(), true));
