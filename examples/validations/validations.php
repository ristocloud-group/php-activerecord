<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/User.php';

$db = __DIR__ . '/validations.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/validations.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

function out(string $s): void
{
    echo $s . "\n";
}

// An optional numeric field: allow_blank skips a blank value ('' or null); anything else is
// still validated. On a TEXT column '' reaches the validator as-is (an INTEGER column casts
// it to 0). Before #50 numericality ignored allow_blank and rejected '' as "is not a number".
require_once __DIR__ . '/models/Parcel.php';
foreach (['', null, '2.5', '0', 'heavy'] as $weight) {
    $parcel = new Parcel(['weight_kg' => $weight]);
    out('weight_kg ' . var_export($weight, true) . ' valid? '
        . ($parcel->is_valid() ? 'yes' : 'no (' . implode('; ', $parcel->errors->full_messages()) . ')'));
}

// A valid record saves.
$ok = new User(['name' => 'Ada', 'email' => 'ada@example.com', 'age' => 36, 'role' => 'member']);
out('valid saved? ' . ($ok->save() ? 'yes' : 'no'));

// An invalid record: fails presence, format, uniqueness, inclusion, and the custom rule.
$bad = new User(['name' => 'a', 'email' => 'not-an-email', 'age' => 999, 'role' => 'wizard']);
out('invalid saved? ' . ($bad->save() ? 'yes' : 'no'));
out('is_valid()? ' . ($bad->is_valid() ? 'yes' : 'no'));
out('errors:');
foreach ($bad->errors->full_messages() as $msg) {
    out('  - ' . $msg);
}

// Reserved-word custom rule + uniqueness on an existing email.
$dup = new User(['name' => 'admin', 'email' => 'taken@example.com', 'age' => 20, 'role' => 'guest']);
$dup->save();
out('errors on name: ' . implode(', ', (array) ($dup->errors->on('name') ?? [])));
out('errors on email: ' . implode(', ', (array) ($dup->errors->on('email') ?? [])));
