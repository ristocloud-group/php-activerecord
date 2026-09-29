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

// A null value is not in the list either (no allow_null): it fails inclusion
// with the usual message, and a custom message's %s renders it as ''. (Before
// #60 this also raised a str_replace() null deprecation on PHP 8.1+.)
require_once __DIR__ . '/models/PrintJob.php';
$no_role = new User(['name' => 'Bob', 'email' => 'bob@example.com']);
$no_role->is_valid();
out('null role -> ' . implode(', ', (array) ($no_role->errors->on('role') ?? [])));
foreach (['spiral', null] as $binding) {
    $job = new PrintJob(['binding' => $binding]);
    $job->is_valid();
    out('binding ' . ($binding ?? 'null') . ' -> ' . implode(', ', (array) ($job->errors->on('binding') ?? [])));
}

// Reserved-word custom rule + uniqueness on an existing email.
$dup = new User(['name' => 'admin', 'email' => 'taken@example.com', 'age' => 20, 'role' => 'guest']);
$dup->save();
out('errors on name: ' . implode(', ', (array) ($dup->errors->on('name') ?? [])));
out('errors on email: ' . implode(', ', (array) ($dup->errors->on('email') ?? [])));
