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

/** @param array<int, ActiveRecord\Model> $tasks */
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

// 3b. A fragment may START with a string literal and still expand an array
//     bind: IN(?) becomes IN(?,?), and a '?' inside the literal stays text.
//     (Before #52 a quote at position 0 was missed, so the IN(?) marker was
//     skipped and the query failed at bind time.) Several scalars take one
//     bind per '?' — ['a = ? AND b = ?', 1, 2] — never one array.
$rows = Task::all(['conditions' => ["'review PR' <> name AND flag IN(?)", [1, 2]]]);
out('leading literal [1,2]:  ' . names($rows));
out('  SQL: ' . Task::table()->last_sql);

// 4. An array containing null matches BOTH the listed values and NULL rows:
//    the library partitions it into (flag IN(?) OR flag IS NULL).
$rows = Task::all(['conditions' => ['flag' => [1, null]]]);
out('flag => [1, null]:      ' . names($rows));
out('  SQL: ' . Task::table()->last_sql);

// 4b. A hash key may name its table: 'labels.name' is quoted part by part, so
//     with `joins` it reaches the joined table, while an unqualified key still
//     gets the model's table ('flag' -> tasks.flag). Before #35 the dotted key
//     was one unknown column, `labels.name`. (Labels: 'urgent' on 'review PR'
//     and on 'cut release'.)
$rows = Task::all([
    'joins' => 'JOIN labels ON (labels.task_id = tasks.id)',
    'conditions' => ['labels.name' => 'urgent', 'flag' => 1],
]);
out('labels.name + joins:    ' . names($rows));
out('  SQL: ' . Task::table()->last_sql);

//     Hash keys are always quoted as identifiers, never spliced in as SQL
//     (#64): a crafted key is one unknown column, not an OR that matches every
//     row. Write expressions as a fragment instead: ['flag + 1 = ?', 2].
try {
    $rows = Task::all(['conditions' => ['flag` IS NOT NULL OR `flag' => 0]]);
    out('crafted hash key:       ' . names($rows));
} catch (ActiveRecord\DatabaseException $e) {
    out('crafted hash key:       rejected, no rows returned');
    out('  ' . $e->getMessage());
}

// 4c. Keys that name the same column ('flag', `flag`, `tasks`.`flag`) are all kept and
//     ANDed with `joins`, exactly as without joins, so a caller's filter cannot replace a
//     mandatory scope. (They used to collapse into the last key: 'review PR' came back.)
$scope = ['flag' => 1];
$rows = Task::all([
    'joins' => 'JOIN labels ON (labels.task_id = tasks.id)',
    'conditions' => $scope + ['`tasks`.`flag`' => 2],
]);
out('scope + same column:    ' . names($rows) . '   <- both conditions apply');
out('  SQL: ' . Task::table()->last_sql);

// 4d. An unqualified hash key that names no column of the model's table (a typo, or a
//     function call such as 'LOWER(name)' — hash keys are never expressions) is
//     rejected before any query, naming the model and the key. It used to reach the
//     database as an unknown column. A table-qualified key ('labels.name') is not checked.
try {
    Task::all(['conditions' => ['nmae' => 'cut release']]);
} catch (ActiveRecord\DatabaseException $e) {
    out('unknown hash key:       ' . $e->getMessage());
}

// 5. The boundary: a user-authored fragment is NOT rewritten — the null is
//    bound as-is, and under SQL three-valued logic the NULL row is excluded.
$rows = Task::all(['conditions' => ['flag IN(?)', [1, null]]]);
out('fragment [1, null]:     ' . names($rows) . '   <- NULL row excluded, unlike case 4');
out('  SQL: ' . Task::table()->last_sql);

// 6. Dynamic finders build their own IN list, so they partition like case 4.
$rows = Task::find_all_by_flag([1, null]);
out('find_all_by_flag:       ' . names($rows));
out('  SQL: ' . Task::table()->last_sql);
