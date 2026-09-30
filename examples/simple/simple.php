<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';

// The simplest possible model: an empty subclass. The table name ("books")
// and every column are introspected from the live database at runtime — the
// model declares no schema. See simple.sql.
/**
 * @property int    $id
 * @property string $name
 * @property string $author
 */
class Book extends ActiveRecord\Model {}

// A model on a table WITHOUT a primary key (simple_page_visits in simple.sql).
// It needs no extra configuration either.
/**
 * @property string $page
 * @property int    $hits
 */
class SimplePageVisit extends ActiveRecord\Model {}

// Create a throwaway SQLite database and load the schema, so this runs with no
// database server to configure.
$db = __DIR__ . '/simple.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/simple.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

// Without a primary key, create() inserts the row and it reads back like any
// other; get_primary_key(true) is simply null. (Up to 2.1.0, every create() on
// such a table raised E_WARNING "Undefined array key 0".) With no key to target
// a row, saving changes to it or delete() throws an ActiveRecordException.
$warnings = [];
set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
    $warnings[] = $errstr;
    return true;
});
SimplePageVisit::create(['page' => '/books', 'hits' => 3]);
var_dump((new SimplePageVisit())->get_primary_key(true));
print_r(array_map(fn(ActiveRecord\Model $visit) => $visit->attributes(), SimplePageVisit::all()));
restore_error_handler();
echo 'warnings raised: ' . count($warnings) . "\n\n";

// Fetch the first row and dump its attributes.
print_r(Book::first()->attributes());
