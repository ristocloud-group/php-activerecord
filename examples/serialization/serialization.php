<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Category.php';
require_once __DIR__ . '/models/Product.php';
require_once __DIR__ . '/models/Review.php';

$db = __DIR__ . '/serialization.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/serialization.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

function out(string $s): void
{
    echo $s . "\n";
}

$p = Product::create([
    'category_id' => 1,
    'name'        => 'Hammer',
    'price'       => 20.0,
    'secret_cost' => 7.5,
]);

// only / except pick or drop columns.
out('to_json only: ' . $p->to_json(['only' => ['name', 'price']]));
out('to_json except: ' . $p->to_json(['except' => ['secret_cost', 'category_id']]));

// methods adds computed values from model methods.
out('to_json methods: ' . $p->to_json(['only' => ['name'], 'methods' => ['discounted_price']]));

// include pulls in an association.
out('to_json include: ' . $p->to_json(['only' => ['name'], 'include' => ['category']]));

// to_xml names included records after their short class name, like the root
// element, so a namespaced has_many renders as <review> elements. (Before #32
// it threw a ValueError on the element name "examples\serialization\review".)
foreach ([5, 4] as $stars) {
    Examples\Serialization\Review::create(['product_id' => $p->id, 'stars' => $stars]);
}
out('to_xml include of namespaced reviews:');
out($p->to_xml(['only' => ['name'], 'include' => ['reviews' => ['only' => ['stars']]]]));

// to_array mirrors the same options; to_xml renders XML.
$arr = $p->to_array(['only' => ['name', 'price']]);
out('to_array keys: ' . implode(', ', array_keys($arr)));
out('to_xml except secret_cost:');
out($p->to_xml(['except' => ['secret_cost']]));
