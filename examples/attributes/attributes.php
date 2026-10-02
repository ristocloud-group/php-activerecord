<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Company.php';
require_once __DIR__ . '/models/Member.php';
require_once __DIR__ . '/models/Badge.php';

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

// Strict mass assignment (opt-in, global, off by default): a key blocked by
// $attr_accessible/$attr_protected throws instead of being dropped, before
// anything is assigned. Assigning one attribute ($m->is_admin = 1) is unaffected.
$cfg = ActiveRecord\Config::instance();
$was_strict = $cfg->get_strict_mass_assignment();
$cfg->set_strict_mass_assignment(true);
try {
    new Member(['first_name' => 'Ada', 'is_admin' => 1]);
} catch (ActiveRecord\MassAssignmentException $e) {
    out('strict mass assignment: ' . $e->getMessage());
} finally {
    $cfg->set_strict_mass_assignment($was_strict);
}

// Delegation: read company.country through the member.
out('delegated country: ' . $m->country);

// isset() / ?? / empty() agree with reads: a declared delegate is set like an
// attribute (even with no target, its value is then null), and on a model whose
// pk is not `id`, `id` is that pk attribute (null on a new record). Before #54
// isset() was false for both, so `$m->country ?? 'n/a'` gave 'n/a' even with a
// company, and reading `$badge->id` on a new record threw.
$loner = new Member(['first_name' => 'Linus']);   // no company
out('isset($m->country)? ' . var_export(isset($m->country), true) . "; \$m->country ?? 'n/a': " . ($m->country ?? 'n/a'));
out('no company: isset($loner->country)? ' . var_export(isset($loner->country), true) . "; \$loner->country ?? 'n/a': " . ($loner->country ?? 'n/a'));
$badge = new Badge(['label' => 'speaker']);   // primary key badge_no
out('new badge: isset($badge->id)? ' . var_export(isset($badge->id), true) . '; $badge->id: ' . var_export($badge->id, true));
$badge->save();
out("saved badge: \$badge->id ?? 'unsaved': " . ($badge->id ?? 'unsaved') . ' (badge_no ' . $badge->badge_no . ')');

// Dirty tracking.
$m->first_name = 'Grace B.';
out('is_dirty()? ' . ($m->is_dirty() ? 'yes' : 'no'));
out('dirty_attributes: ' . implode(', ', array_keys($m->dirty_attributes())));
$m->save();
out('is_dirty() after save? ' . ($m->is_dirty() ? 'yes' : 'no'));
