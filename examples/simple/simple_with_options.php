<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';

// A model that overrides the naming conventions. By default a `Book` model would
// map to a `books` table with an `id` primary key; here we point it at a
// differently-named table and primary key.
/**
 * @property int    $book_id
 * @property string $name
 */
class Book extends ActiveRecord\Model
{
    // Explicit table name, since our table is not the inferred "books".
    public static $table_name = 'simple_book';

    // Explicit primary key, since ours is not the inferred "id".
    public static $primary_key = 'book_id';

    // A model can also pin a named connection with `public static $connection`
    // (e.g. 'production'), useful when different models live in different
    // databases. We omit it here so this example uses the default connection.
}

// Create a throwaway SQLite database and load the schema, so this runs with no
// database server to configure.
$db = __DIR__ . '/simple_with_options.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/simple_with_options.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

// Fetch the first row (via the custom pk/table) and dump its attributes.
print_r(Book::first()->attributes());
