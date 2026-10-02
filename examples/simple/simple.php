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

// A record that was never saved has a null primary key value: updating or
// deleting it throws an ActiveRecordException before any SQL or callback runs.
// (Up to 2.1.0 both ran "... WHERE id IS NULL", matched no row and returned
// true; changing the key of a loaded record is refused the same way, since the
// statement used to hit the row under the new key.)
$unsaved = new Book(['name' => 'Never saved']);
$writes = [
    'update_attribute' => fn() => $unsaved->update_attribute('author', 'Nobody'),
    'delete' => fn() => $unsaved->delete(),
];
foreach ($writes as $call => $write) {
    try {
        echo "$call() returned " . var_export($write(), true) . "\n";
    } catch (ActiveRecord\ActiveRecordException $e) {
        echo "$call() threw: " . $e->getMessage() . "\n";
    }
}

// A deleted record is refused the same way (up to 2.1.0 a later save() ran an
// UPDATE on the deleted row and returned true), unless the delete was rolled
// back by Model::transaction(): then the record is writable again.
$book = Book::create(['name' => 'Short-lived', 'author' => 'Ann']);
Book::transaction(function () use ($book): bool {
    $book->delete();
    return false;
});
echo 'after a rolled-back delete, update_attribute() returned ' . var_export($book->update_attribute('author', 'Bea'), true) . "\n";
$book->delete();
try {
    $book->update_attribute('author', 'Cy');
} catch (ActiveRecord\ActiveRecordException $e) {
    echo 'after delete(), update_attribute() threw: ' . $e->getMessage() . "\n";
}
echo 'books in the table: ' . Book::count() . "\n\n";

// Fetch the first row and dump its attributes.
print_r(Book::first()->attributes());
