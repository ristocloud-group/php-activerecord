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

// Length ranges with a zero or an exact bound: 'within' => [0, N] makes a field
// optional with a maximum length, [N, N] requires exactly N characters.
// (Before #56 both threw ValidationsArgumentError instead of validating.)
require_once __DIR__ . '/models/LengthRangeUser.php';
foreach ([['ADA', ''], ['ADA', 'member'], ['AD', 'a-very-long-role']] as [$name, $role]) {
    $u = new LengthRangeUser(['name' => $name, 'role' => $role]);
    out(sprintf(
        'length name=%s role=%s: %s',
        var_export($name, true),
        var_export($role, true),
        $u->is_valid() ? 'valid' : implode('; ', $u->errors->full_messages()),
    ));
}

// Reserved-word custom rule + uniqueness on an existing email.
$dup = new User(['name' => 'admin', 'email' => 'taken@example.com', 'age' => 20, 'role' => 'guest']);
$dup->save();
out('errors on name: ' . implode(', ', (array) ($dup->errors->on('name') ?? [])));
out('errors on email: ' . implode(', ', (array) ($dup->errors->on('email') ?? [])));
