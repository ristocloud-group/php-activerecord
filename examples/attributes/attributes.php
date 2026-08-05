<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Company.php';
require_once __DIR__ . '/models/Member.php';

$db = __DIR__ . '/attributes.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/attributes.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

function out(string $s): void
{
    echo $s . "\n";
}

// Mass assignment respects $attr_accessible: is_admin is dropped.
$m = new Member([
    'first_name' => 'Grace',
    'last_name'  => 'Hopper',
    'email'      => 'grace@example.com',
    'company_id' => 1,
    'is_admin'   => 1,   // ignored (not in $attr_accessible)
]);
$m->password = 's3cret';           // custom setter -> password_hash
$m->save();

out('full_name (custom getter): ' . $m->full_name);
out('is_admin after mass-assign (protected): ' . (int) $m->is_admin);   // 0
out('password stored as hash: ' . $m->password_hash);
out('alias_attribute email_address: ' . $m->email_address);

// Delegation: read company.country through the member.
out('delegated country: ' . $m->country);

// Dirty tracking.
$m->first_name = 'Grace B.';
out('is_dirty()? ' . ($m->is_dirty() ? 'yes' : 'no'));
out('dirty_attributes: ' . implode(', ', array_keys($m->dirty_attributes())));
$m->save();
out('is_dirty() after save? ' . ($m->is_dirty() ? 'yes' : 'no'));
