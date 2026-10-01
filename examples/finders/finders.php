<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Widget.php';

$db = __DIR__ . '/finders.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/finders.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

function out(string $s): void
{
    echo $s . "\n";
}

// Dynamic finders (they return a model or null -> "??" already guards against
// null on its left side, so plain "->" is enough, no "?->" needed).
out('find_by_name: ' . (Widget::find_by_name('Alpha')->name ?? '(none)'));
out('find_all_by_category(gizmos): ' . count(Widget::find_all_by_category('gizmos')));
out('find_by_category_and_in_stock: ' . (Widget::find_by_category_and_in_stock('gadgets', 1)->name ?? '(none)'));

// Option set: conditions / order / limit / offset / select.
$page = Widget::all([
    'select'     => 'name, price',
    'conditions' => ['price > ?', 5.0],
    'order'      => 'price desc',
    'limit'      => 2,
    'offset'     => 1,
]);
out('page names: ' . implode(', ', ActiveRecord\collect($page, 'name')));

// 'limit' => 0 is a real LIMIT 0 (a page of size 0 has no rows), and an 'offset'
// without a 'limit' returns every row after the offset. (Before #34 the first
// returned ALL rows and the second NONE: it rendered "LIMIT 2,0".)
$none = Widget::all(['order' => 'id', 'limit' => 0]);
out('limit 0: ' . count($none) . ' rows');
out('  SQL: ' . Widget::table()->last_sql);
out('count(limit 0): ' . Widget::count(['limit' => 0]));
$rest = Widget::all(['order' => 'id', 'offset' => 2]);
out('offset 2, no limit: ' . implode(', ', ActiveRecord\collect($rest, 'name')));
out('  SQL: ' . Widget::table()->last_sql);

// last() reverses the order: only each item's own trailing asc/desc is flipped
// (an item without one gets DESC), so a column like "description" is left
// intact. (Before #37 it became "ASCription" and the query failed.)
foreach (['description desc', 'category asc, description'] as $order) {
    /** @var Widget|null $last */
    $last = Widget::last(['order' => $order]);
    out("last() by '$order': " . ($last->name ?? '(none)'));
    out('  SQL: ' . Widget::table()->last_sql);
}

// group / having (aggregate).
$rows = Widget::all([
    'select' => 'category, COUNT(*) AS n',
    'group'  => 'category',
    'having' => 'COUNT(*) > 1',
]);
out('categories with >1 widget: ' . implode(', ', ActiveRecord\collect($rows, 'category')));

// Raw SQL escape hatch.
$raw = Widget::find_by_sql('SELECT * FROM widgets WHERE in_stock = 1 ORDER BY price');
out('in-stock via find_by_sql: ' . count($raw));

// Static scope.
$cheap = Widget::cheap();
out('cheap(): ' . implode(', ', ActiveRecord\collect($cheap, 'name')));
