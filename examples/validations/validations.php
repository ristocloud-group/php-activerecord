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

// Reserved-word custom rule + uniqueness on an existing email.
$dup = new User(['name' => 'admin', 'email' => 'taken@example.com', 'age' => 20, 'role' => 'guest']);
$dup->save();
out('errors on name: ' . implode(', ', (array) ($dup->errors->on('name') ?? [])));
out('errors on email: ' . implode(', ', (array) ($dup->errors->on('email') ?? [])));
