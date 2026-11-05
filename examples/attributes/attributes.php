<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/models/Company.php';
require_once __DIR__ . '/models/Member.php';
require_once __DIR__ . '/models/Badge.php';
require_once __DIR__ . '/models/Contact.php';

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

// Integer columns cast to int, except an integer beyond the PHP int range (e.g.
// a MySQL BIGINT UNSIGNED above PHP_INT_MAX, which PDO returns as a string): it
// keeps its exact string. (Before #44 it was clamped to PHP_INT_MAX, so a save
// or delete keyed on it hit the row whose id is PHP_INT_MAX.)
$n = new Member();
$n->set_attributes(['company_id' => '42']);
out("company_id from '42': " . var_export($n->company_id, true));
$n->set_attributes(['company_id' => '18446744073709551615']);
out("company_id from '18446744073709551615': " . var_export($n->company_id, true));

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

// Columns whose names are not attribute names: the column "first name" is the
// attribute first_name, the column lastName the attribute lastname. Queries name
// the real column, so save(), the finders and count() take the attribute names.
// (Before #92 they sent first_name as a column name and failed on every database.)
// A name that differs only by case, like lastName, is renamed only on Postgres,
// which matches a quoted name's case; SQLite and MySQL match lastname as it is.
$contact = new Contact(['first_name' => 'Ada', 'lastname' => 'Byron']);
$contact->save();
$found = Contact::find_by_first_name('Ada');
if (null !== $found) {
    $found->lastname = 'Lovelace';
    $found->save();
}
$contact->reload();
out('contact (column "first name"): ' . $contact->first_name . ' ' . $contact->lastname);
out("count(['first_name' => 'Ada']): " . Contact::count(['conditions' => ['first_name' => 'Ada']]));

// MySQL/MariaDB: a tinyint(1) column is a boolean by convention. A legacy schema
// that stores small integers in tinyint(1) turns the convention off with
// Config::set_tinyint_as_boolean(false) (#195): the column is then an integer,
// as in 1.7.1. Set it in Config::initialize(), before the first model is used:
// column types are read once per table. This half needs a reachable MySQL and is
// skipped otherwise: set PHPAR_MYSQL to a mysql:// URL (this repo's Docker setup does).
class TinyStatus extends ActiveRecord\Model
{
    public static $connection = 'mysql';
    public static $table_name = 'example_tiny_statuses';
}

$mysql_url = getenv('PHPAR_MYSQL') ?: '';
if ('' === $mysql_url) {
    out('tinyint(1) half skipped: set PHPAR_MYSQL to a mysql:// URL to run it.');
    exit(0);
}

$cfg->set_connections([...$cfg->get_connections(), 'mysql' => $mysql_url]);
try {
    $mysql = ActiveRecord\ConnectionManager::get_connection('mysql');
} catch (ActiveRecord\DatabaseException $e) {
    out('tinyint(1) half skipped: connection failed (' . strtok($e->getMessage(), "\n") . ')');
    exit(0);
}

$mysql->query('DROP TABLE IF EXISTS example_tiny_statuses');
$mysql->query('CREATE TABLE example_tiny_statuses (id INT AUTO_INCREMENT PRIMARY KEY, stato TINYINT(1) NOT NULL)');
$mysql->query('INSERT INTO example_tiny_statuses (stato) VALUES (4)');

try {
    out('tinyint(1) holding 4, convention on (default): ' . var_export(TinyStatus::find(1)->stato, true));   // true

    // the example flips the option mid-script, so it drops the in-memory column cache
    $cfg->set_tinyint_as_boolean(false);
    ActiveRecord\Table::clear_cache(TinyStatus::class);

    $status = TinyStatus::find(1);
    out('tinyint(1) holding 4, convention off: ' . var_export($status->stato, true));                       // 4
    $status->stato = -1;
    $status->save();
    out('written back and serialised: ' . TinyStatus::find(1)->to_json());                                   // {"id":1,"stato":-1}
} finally {
    $cfg->set_tinyint_as_boolean(true);
    $mysql->query('DROP TABLE example_tiny_statuses');
}
