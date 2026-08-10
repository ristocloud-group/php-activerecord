<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Task.php';

$db = __DIR__ . '/conditions.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/conditions.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

function out(string $s): void
{
    echo $s . "\n";
}

/** @param array<int, Task> $tasks */
function names(array $tasks): string
{
    return $tasks === [] ? '(no rows)' : implode(', ', ActiveRecord\collect($tasks, 'name'));
}

// Rows: 'write docs' (flag 1), 'review PR' (flag 2), 'cut release' (flag 1),
// 'triage inbox' (flag NULL).

// 1. A null hash value renders a literal IS NULL — it matches the NULL row.
$rows = Task::all(['conditions' => ['flag' => null]]);
out('flag => null:           ' . names($rows));
out('  SQL: ' . Task::table()->last_sql);

// 2. An empty array yields an empty result set (renders 1=0), not an exception.
$rows = Task::all(['conditions' => ['id' => []]]);
out('id => []:               ' . names($rows));
out('  SQL: ' . Task::table()->last_sql);

// 3. An empty array bound in a user-written fragment expands to IN(NULL) —
//    valid SQL everywhere, matches nothing.
$rows = Task::all(['conditions' => ['id = ? AND id IN(?)', 5, []]]);
out('fragment IN(?) with []: ' . names($rows));
out('  SQL: ' . Task::table()->last_sql);

// 4. An array containing null matches BOTH the listed values and NULL rows:
//    the library partitions it into (flag IN(?) OR flag IS NULL).
$rows = Task::all(['conditions' => ['flag' => [1, null]]]);
out('flag => [1, null]:      ' . names($rows));
out('  SQL: ' . Task::table()->last_sql);

// 5. The boundary: a user-authored fragment is NOT rewritten — the null is
//    bound as-is, and under SQL three-valued logic the NULL row is excluded.
$rows = Task::all(['conditions' => ['flag IN(?)', [1, null]]]);
out('fragment [1, null]:     ' . names($rows) . '   <- NULL row excluded, unlike case 4');
out('  SQL: ' . Task::table()->last_sql);

// 6. Dynamic finders build their own IN list, so they partition like case 4.
$rows = Task::find_all_by_flag([1, null]);
out('find_all_by_flag:       ' . names($rows));
out('  SQL: ' . Task::table()->last_sql);
