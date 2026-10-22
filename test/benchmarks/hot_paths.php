<?php

/**
 * Throughput of the library's hot paths, in operations per second: the numbers to compare
 * before and after a change to the query path. A script, not a test: nothing here asserts.
 *
 *     docker compose exec -T -e PHPAR_CONNECTION=mysql tests php test/benchmarks/hot_paths.php
 *
 * It resets the test tables as every test does, so run it where the tests run and never at
 * the same time as them. Compare runs of the same connection on an idle machine, and repeat
 * them: a path that waits on the database moves by a few percent from one run to the next, and
 * far more on a busy host. The cpu columns are the PHP process's own time per operation, with no
 * waiting on the database in it: the steadier numbers to compare.
 *
 * The last path is not one the database sees: it only builds the SELECT of a finder, which is
 * the code the query engine took over, and no round trip hides what it costs.
 */

use ActiveRecord\Config;
use ActiveRecord\ConnectionManager;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/../helpers/DatabaseLoader.php';
require_once __DIR__ . '/../helpers/model_autoloader.php';

date_default_timezone_set('UTC');

$connection_name = getenv('PHPAR_CONNECTION') ?: 'mysql';

// the connections of test/helpers/config.php, without its logger: a log line per statement
// would be the loudest thing this script measures
Config::initialize(function (Config $cfg) use ($connection_name): void {
    $cfg->set_connections([
        'mysql' => getenv('PHPAR_MYSQL') ?: 'mysql://test:test@127.0.0.1/test',
        'mariadb' => getenv('PHPAR_MARIADB') ?: 'mysql://test:test@127.0.0.1/test',
        'pgsql' => getenv('PHPAR_PGSQL') ?: 'pgsql://test:test@127.0.0.1/test',
        'sqlite' => getenv('PHPAR_SQLITE') ?: 'sqlite://test.db']);
    $cfg->set_default_connection($connection_name);
});

if ('sqlite' === $connection_name) {
    // the adapter does not create the database file
    new SQLite3(substr(Config::instance()->get_connection('sqlite'), 9));
}

$conn = ConnectionManager::get_connection();
(new DatabaseLoader($conn, $connection_name))->reset_table_data();

/**
 * Runs $operation once to warm it up (schema, autoload), then $iterations times, timed.
 */
$measure = function (string $label, int $iterations, callable $operation): void {
    $operation(0);
    gc_collect_cycles();

    $cpu_before = getrusage();
    $start = hrtime(true);

    for ($i = 1; $i <= $iterations; $i++) {
        $operation($i);
    }

    $seconds = (hrtime(true) - $start) / 1e9;
    $cpu_after = getrusage();
    $cpu = fn(string $kind): float => (($cpu_after["ru_{$kind}time.tv_sec"] - $cpu_before["ru_{$kind}time.tv_sec"]) * 1e6 + $cpu_after["ru_{$kind}time.tv_usec"] - $cpu_before["ru_{$kind}time.tv_usec"]) / $iterations;

    printf("%-50s %7d ops %8.3f s %10.1f ops/s %8.2f us/op   cpu us/op: %8.2f user %7.2f sys\n", $label, $iterations, $seconds, $iterations / $seconds, 1e6 * $seconds / $iterations, $cpu('u'), $cpu('s'));
};

printf("php-activerecord hot paths: connection \"%s\" (%s), PHP %s\n", $connection_name, $conn->protocol, PHP_VERSION);

$measure('Author::find(1)', 20000, fn() => Author::find(1));
$measure('Author::all()', 5000, fn() => Author::all());
$measure('Author::count()', 20000, fn() => Author::count());

// the writes run in one transaction that is rolled back (a closure that returns false)
Author::transaction(function () use ($measure): bool {
    $measure('Author::create() + save(), one rolled-back transaction', 2000, function (int $i): void {
        $author = Author::create(['name' => "bench $i"]);
        $author->name = "renamed $i";
        $author->save();
    });

    return false;
});

if (4 !== (int) Author::count()) {
    fwrite(STDERR, "the transaction was not rolled back: the authors table holds more than its 4 fixtures\n");
    exit(1);
}

$measure('Table::options_to_sql() + to_s(), no database', 200000, fn() => Author::table()->options_to_sql(['conditions' => ['name' => 'Tito'], 'order' => 'name', 'limit' => 1])->to_s());
