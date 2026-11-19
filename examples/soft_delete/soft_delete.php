<?php

// Soft delete: a SoftDeletable model hides its trashed rows from every query, with_trashed() / only_trashed() choose
// which rows a query reads, and delete(), restore() and force_delete() write.

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/models/ShopOrder.php';

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
show('live orders (builder)', numbers(ShopOrder::order_by('id')->get()));
show('live orders (all())', numbers(ShopOrder::all(['order' => 'id'])));
show('count()', (string) ShopOrder::count());
show('first() of ORD-002', var_export(ShopOrder::where('number', 'ORD-002')->first(), true));
show('SQL', ShopOrder::where('number', 'ORD-001')->to_sql());

// 2. with_trashed(): the trashed rows with the live ones.
show('with_trashed()', numbers(ShopOrder::with_trashed()->order_by('id')->get()));

// 3. only_trashed(): the trashed rows only. The filter is replaced, never ANDed with the default one.
show('only_trashed()', numbers(ShopOrder::only_trashed()->order_by('id')->get()));
show('only_trashed() SQL', ShopOrder::only_trashed()->to_sql());

// 4. The last call wins: without_trashed() brings the default filter back.
show('only_trashed()->without_trashed()', numbers(ShopOrder::only_trashed()->without_trashed()->order_by('id')->get()));

// 5. trashed() tells a trashed record from a live one.
$trashed = ShopOrder::with_trashed()->where('number', 'ORD-002')->first();
$live = ShopOrder::where('number', 'ORD-001')->first();
show('ORD-002 trashed()', var_export($trashed?->trashed(), true));
show('ORD-001 trashed()', var_export($live?->trashed(), true));

// 6. delete() on an instance trashes the row: it stays in the table, hidden, and the object is not destroyed.
$order = ShopOrder::where('number', 'ORD-001')->first();
$order?->delete();
show('after delete(): live orders', numbers(ShopOrder::order_by('id')->get()));
show('after delete(): only_trashed()', numbers(ShopOrder::only_trashed()->order_by('id')->get()));
show('ORD-001 trashed()', var_export($order?->trashed(), true));

// 7. restore() brings it back: a direct UPDATE of the column to NULL, with no save() and no validations.
$order?->restore();
show('after restore(): live orders', numbers(ShopOrder::order_by('id')->get()));
show('ORD-001 trashed()', var_export($order?->trashed(), true));

// 8. force_delete() really deletes the row, trashed or not.
$trashed?->force_delete();
show('after force_delete(): rows', numbers(ShopOrder::with_trashed()->order_by('id')->get()));

// 9. Bulk: delete() on a query is an UPDATE of the column; force_delete() on a query is a DELETE.
show('bulk delete() count', (string) ShopOrder::where('number', 'ORD-003')->delete());
show('after bulk delete(): live orders', numbers(ShopOrder::order_by('id')->get()));
show('bulk force_delete() count', (string) ShopOrder::only_trashed()->force_delete());
show('after bulk force_delete(): rows', numbers(ShopOrder::with_trashed()->order_by('id')->get()));
