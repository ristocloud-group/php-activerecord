<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Widget.php';
require_once __DIR__ . '/models/PgWidget.php';
require_once __DIR__ . '/models/PgWidgetLog.php';

$db = __DIR__ . '/finders.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/finders.sql'));
$pdo = null;

// The last part needs a reachable Postgres and is skipped otherwise: set
// PHPAR_PGSQL to a pgsql:// URL (this repo's Docker setup already does).
$pgsql_url = getenv('PHPAR_PGSQL') ?: '';

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db, $pgsql_url) {
    $connections = ['development' => 'sqlite://unix(' . $db . ')'];

    if ('' !== $pgsql_url) {
        $connections['pgsql'] = $pgsql_url;
    }

    $cfg->set_connections($connections);
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

// 'limit' and 'order' on delete_all()/update_all() touch only the first rows in
// that order: SQLite and MySQL/MariaDB render ORDER BY ... LIMIT on the
// statement itself. (Postgres has neither: see the end of this example.)
Widget::transaction(function (): bool {
    $gadgets = ['category = ?', 'gadgets'];
    out('delete_all(gadgets, order price desc, limit 1): ' . Widget::delete_all(['conditions' => $gadgets, 'order' => 'price desc', 'limit' => 1]) . ' row (rolled back)');
    out('  SQL: ' . Widget::table()->last_sql);
    $left = Widget::all(['conditions' => $gadgets]);
    out('  gadgets left: ' . implode(', ', ActiveRecord\collect($left, 'name')));

    return false;
});

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

// first()/last() take the record at an 'offset' (last() counts it from the end of
// its order), and a 'limit' of 0 finds nothing. (Up to 2.1.0 both were ignored:
// first()/last() always took one record at offset 0 - LIMIT 0,1 on SQLite and
// MySQL/MariaDB, LIMIT 1 OFFSET 0 on Postgres - so 'offset' => 1 still gave the first.)
/** @var Widget|null $second */
$second = Widget::first(['order' => 'price', 'offset' => 1]);
out('first(order price, offset 1): ' . ($second->name ?? '(none)'));
out('  SQL: ' . Widget::table()->last_sql);
/** @var Widget|null $second_to_last */
$second_to_last = Widget::last(['order' => 'price', 'offset' => 1]);
out('last(order price, offset 1): ' . ($second_to_last->name ?? '(none)'));
out('  SQL: ' . Widget::table()->last_sql);
/** @var Widget|null $nothing */
$nothing = Widget::first(['limit' => 0]);
out('first(limit 0): ' . ($nothing->name ?? '(none)'));
out('  SQL: ' . Widget::table()->last_sql);

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

// exists()/count() read their pk arguments as find() does: 0 is a pk value (no
// widget has the id 0), and several pks are the array form; only null, '' and []
// mean "no pk" (the whole table). (Up to 2.1.0 exists(0) and count(0) looked at
// the whole table, and count(1, 2, 3) counted only the id 1.)
out('exists(0): ' . var_export(Widget::exists(0), true) . ', count(0): ' . Widget::count(0));
out('count(1, 2, 3): ' . Widget::count(1, 2, 3) . ' = count([1, 2, 3]): ' . Widget::count([1, 2, 3]));
out('  SQL: ' . Widget::connection()->last_query);

// A pk passed with conditions is found only if it matches them too, like Rails'
// where(...).find($id): handy to scope a lookup to an owner. (Up to 2.1.0 find()
// dropped the pk and returned the first widget matching the conditions, while
// count()/exists() dropped the conditions.)
out("find(1, category 'gadgets'): " . Widget::find(1, ['conditions' => ['category' => 'gadgets']])->name);
out('  SQL: ' . Widget::table()->last_sql);
try {
    Widget::find(1, ['conditions' => ['category' => 'gizmos']]);
} catch (ActiveRecord\RecordNotFound $e) {
    out("find(1, category 'gizmos'): " . $e->getMessage());
}
out("count(1, 3, category 'gizmos'): " . Widget::count(1, 3, ['category' => 'gizmos'])
    . ", exists(1, category 'gizmos'): " . var_export(Widget::exists(1, ['conditions' => ['category' => 'gizmos']]), true));

// find('first'|'last', <pk>...) is the first/last of the records those pks find
// (one array of pks, or several values), ANDed with any conditions as find($id, ...)
// is. (Up to 2.1.0 several pks threw RecordNotFound, an array of them a
// DatabaseException, and with conditions the pks were dropped: it returned the
// first widget matching the conditions.)
out('find(first, [2, 3]): ' . Widget::find('first', [2, 3])->name);
out('  SQL: ' . Widget::table()->last_sql);
out('find(last, 2, 3): ' . Widget::find('last', 2, 3)->name);
out('  SQL: ' . Widget::table()->last_sql);
try {
    Widget::find('first', 1, ['conditions' => ['category' => 'gizmos']]);
} catch (ActiveRecord\RecordNotFound $e) {
    out("find(first, 1, category 'gizmos'): " . $e->getMessage());
    out('  SQL: ' . Widget::table()->last_sql);
}

// find() by a pk value that is not an integer finds nothing: RecordNotFound,
// on every adapter (Postgres included, see the end of this example).
foreach (['abc', 'name = 123123123'] as $id) {
    try {
        Widget::find($id);
    } catch (ActiveRecord\RecordNotFound $e) {
        out("find('$id'): " . $e->getMessage());
    }
}

// --- Postgres: limit/order on delete_all()/update_all() ---
//
// Postgres has no LIMIT/ORDER BY on DELETE and UPDATE, so the library picks the
// rows by primary key in a subquery, as Rails does, and a table without a
// primary key by its row identity (tableoid, ctid). (Before #92 the limit and
// the order were silently dropped there: a limited delete_all() deleted EVERY
// matching row.)

if ('' === $pgsql_url) {
    out('');
    out('Postgres half skipped: set PHPAR_PGSQL to a pgsql:// URL to run it.');
    exit(0);
}

try {
    $pg = ActiveRecord\ConnectionManager::get_connection('pgsql');
} catch (ActiveRecord\DatabaseException $e) {
    out('');
    out('Postgres half skipped: connection failed (' . strtok($e->getMessage(), "\n") . ')');
    exit(0);
}

$statements = array_filter(array_map('trim', explode(';', (string) file_get_contents(__DIR__ . '/finders-pgsql.sql'))));
foreach ($statements as $statement) {
    $pg->query($statement);
}

out('');
$gadgets = ['category = ?', 'gadgets'];
out('Postgres delete_all(gadgets, order price desc, limit 1): ' . PgWidget::delete_all(['conditions' => $gadgets, 'order' => 'price desc', 'limit' => 1]) . ' row');
out('  SQL: ' . PgWidget::table()->last_sql);
$left = PgWidget::all(['conditions' => $gadgets]);
out('  gadgets left: ' . implode(', ', ActiveRecord\collect($left, 'name')));

out('Postgres update_all(set in_stock 0, order price, limit 2): ' . PgWidget::update_all(['set' => ['in_stock' => 0], 'order' => 'price', 'limit' => 2]) . ' rows');
out('  SQL: ' . PgWidget::table()->last_sql);
$out_of_stock = PgWidget::all(['conditions' => ['in_stock = ?', 0], 'order' => 'price']);
out('  out of stock: ' . implode(', ', ActiveRecord\collect($out_of_stock, 'name')));

// pg_widget_logs has no primary key: its rows are picked by (tableoid, ctid).
out('Postgres delete_all on a table without a primary key (order widget_id, limit 1): ' . PgWidgetLog::delete_all(['order' => 'widget_id', 'limit' => 1]) . ' row');
out('  SQL: ' . PgWidgetLog::table()->last_sql);
out('  log rows left: ' . PgWidgetLog::count());

// find() by a pk value Postgres cannot compare with the INTEGER pk ('abc',
// '1.5', 'name = 123123123') throws RecordNotFound without querying, so an open
// transaction stays usable. (Before #92 the query failed with a DatabaseException,
// SQLSTATE 22P02, and every later query in the transaction with 25P02.)
PgWidget::transaction(function (): bool {
    foreach (['abc', '1.5'] as $id) {
        try {
            PgWidget::find($id);
        } catch (ActiveRecord\RecordNotFound $e) {
            out("Postgres find('$id') in a transaction: " . $e->getMessage());
        }
    }

    try {
        PgWidget::find(1, 'abc');
    } catch (ActiveRecord\RecordNotFound $e) {
        out("Postgres find(1, 'abc'): " . $e->getMessage());
        out('  SQL: ' . PgWidget::table()->last_sql);
    }
    out('  the transaction goes on: find(1) -> ' . PgWidget::find(1)->name);

    return false;
});

$pg->query('DROP TABLE IF EXISTS pg_widgets, pg_widget_logs');
