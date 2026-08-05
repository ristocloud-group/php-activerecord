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

// Dynamic finders (they return a model or null -> use nullsafe access).
out('find_by_name: ' . (Widget::find_by_name('Alpha')?->name ?? '(none)'));
out('find_all_by_category(gizmos): ' . count(Widget::find_all_by_category('gizmos')));
out('find_by_category_and_in_stock: ' . (Widget::find_by_category_and_in_stock('gadgets', 1)?->name ?? '(none)'));

// Option set: conditions / order / limit / offset / select.
$page = Widget::all([
    'select'     => 'name, price',
    'conditions' => ['price > ?', 5.0],
    'order'      => 'price desc',
    'limit'      => 2,
    'offset'     => 1,
]);
out('page names: ' . implode(', ', ActiveRecord\collect($page, 'name')));

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
