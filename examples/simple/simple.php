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

// Fetch the first row and dump its attributes.
print_r(Book::first()->attributes());
