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

// allow_blank: a blank value (null, '' or an empty array) skips the rule; any other value
// is still validated. Before #58 an empty array made the blank check throw a TypeError.
require_once __DIR__ . '/models/Signup.php';
$blank = new Signup(['email' => '']);
$blank->assign_attribute('topic', []);
out('blank email + empty topic valid? ' . ($blank->is_valid() ? 'yes' : 'no'));
$filled = new Signup(['email' => 'bo@example.com']);
$filled->assign_attribute('topic', 'php');
out('good email + known topic valid? ' . ($filled->is_valid() ? 'yes' : 'no'));
$filled = new Signup(['email' => 'not-an-email']);
$filled->assign_attribute('topic', 'cobol');
out('bad email + unknown topic valid? ' . ($filled->is_valid() ? 'yes' : 'no')
    . ' (' . implode('; ', $filled->errors->full_messages()) . ')');

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

// Numericality odd/even truncates a non-integer first, like Rails'
// value.to_i.odd?: 3.5 counts as odd and 4.5 as even. (Before #59 the same
// results came with an "Implicit conversion from float" deprecation.)
$range = new PrintJob(['binding' => 'paperback', 'first_page' => '3.5', 'last_page' => '4.5']);
out('pages 3.5-4.5 valid? ' . ($range->is_valid() ? 'yes' : 'no'));
$range->last_page = '3.5';
$range->is_valid();
out('last_page 3.5 -> ' . implode(', ', (array) ($range->errors->on('last_page') ?? [])));

// Reserved-word custom rule + uniqueness on an existing email.
$dup = new User(['name' => 'admin', 'email' => 'taken@example.com', 'age' => 20, 'role' => 'guest']);
$dup->save();
out('errors on name: ' . implode(', ', (array) ($dup->errors->on('name') ?? [])));
out('errors on email: ' . implode(', ', (array) ($dup->errors->on('email') ?? [])));
