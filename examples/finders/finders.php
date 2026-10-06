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

// A column whose own name contains _and_ / _or_ stays whole: the finder name is
// split only into real column (or alias) names, preferring one name per value.
// (Before #53 it was split at every _and_: "shipping=? AND handling IS NULL".)
$free = Widget::find_all_by_shipping_and_handling(0);
out('find_all_by_shipping_and_handling(0): ' . implode(', ', ActiveRecord\collect($free, 'name')));
out('  SQL: ' . Widget::table()->last_sql);
out('find_by_shipping_and_handling_and_category: ' . (Widget::find_by_shipping_and_handling_and_category(0, 'gizmos')->name ?? '(none)'));
out('  SQL: ' . Widget::table()->last_sql);

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

// A DateTime / DateTimeImmutable / ActiveRecord\DateTime bind value works in
// count(), exists(), delete_all() and update_all() as in all(): it is bound in
// the connection's datetime format. (Before #138 those four threw "Error: Object
// of class DateTimeImmutable could not be converted to string", and bound an
// ActiveRecord\DateTime as its RFC 2822 text, e.g. 'Sun, 01 Mar 2026 ...'.)
$cutoff = new DateTimeImmutable('2026-03-01');
$stale = ['restocked_at < ?', $cutoff];
out('restocked before 2026-03-01: all() ' . count(Widget::all(['conditions' => $stale]))
    . ', count() ' . Widget::count(['conditions' => $stale])
    . ', exists() ' . var_export(Widget::exists(['conditions' => $stale]), true));
Widget::transaction(function () use ($stale): bool {
    out('delete_all(stale): ' . Widget::delete_all(['conditions' => $stale]) . ' rows (rolled back)');
    out('  SQL: ' . Widget::table()->last_sql);

    return false;
});
$restocked = Widget::update_all(['set' => ['restocked_at' => new DateTime('2026-03-02 09:30:00')], 'conditions' => $stale]);
out("update_all(set restocked_at, stale): $restocked rows -> stale now " . Widget::count(['conditions' => $stale]));

// delete_all() reads a hash without option keys as its conditions, as count()
// and exists() do, and update_all() refuses a key beside 'set' that is not an
// option, before any SQL. (Before #145 both ignored those keys and acted on
// EVERY row: "DELETE FROM `widgets`" deleted all 4 widgets, and the update set
// in_stock = 0 on all 4.)
Widget::transaction(function (): bool {
    out("count(['category' => 'gizmos']): " . Widget::count(['category' => 'gizmos']));
    out("delete_all(['category' => 'gizmos']): " . Widget::delete_all(['category' => 'gizmos']) . ' rows (rolled back)');
    out('  SQL: ' . Widget::table()->last_sql);

    try {
        Widget::update_all(['set' => ['in_stock' => 0], 'category' => 'gizmos']);
    } catch (ActiveRecord\ActiveRecordException $e) {
        out("update_all(['set' => ['in_stock' => 0], 'category' => 'gizmos']): " . $e->getMessage());
    }

    return false;
});

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
