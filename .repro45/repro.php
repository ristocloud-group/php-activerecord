<?php
require __DIR__ . '/../vendor/autoload.php';

$sqlite = '/tmp/issue45.db';
@unlink($sqlite); touch($sqlite);
$mc = getenv('PHPAR_MEMCACHED') ?: 'memcached';

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($sqlite, $mc) {
    $cfg->set_connections([
        'mysql' => getenv('PHPAR_MYSQL'),
        'sqlite' => "sqlite://unix($sqlite)",
    ]);
    $cfg->set_default_connection('mysql');
    $cfg->set_cache("memcache://$mc", ['namespace' => 'repro45', 'expire' => 60]);
});
ActiveRecord\Cache::flush();

$my = ActiveRecord\ConnectionManager::get_connection('mysql');
$my->query('DROP TABLE IF EXISTS i45_collide');
$my->query('CREATE TABLE i45_collide (id INT AUTO_INCREMENT PRIMARY KEY, mysql_only VARCHAR(20))');
ActiveRecord\ConnectionManager::get_connection('sqlite')
    ->query('CREATE TABLE i45_collide (id INTEGER PRIMARY KEY, sqlite_only TEXT)');

class MysqlCollide45 extends ActiveRecord\Model { public static $table_name = 'i45_collide'; public static $connection = 'mysql'; }
class SqliteCollide45 extends ActiveRecord\Model { public static $table_name = 'i45_collide'; public static $connection = 'sqlite'; }

echo 'mysql : ', implode(', ', array_keys(MysqlCollide45::table()->columns)), "\n";
echo 'sqlite: ', implode(', ', array_keys(SqliteCollide45::table()->columns)), "  (real: id, sqlite_only)\n";
try { SqliteCollide45::create(['id' => 1]); echo "sqlite write OK\n"; }
catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$my->query('DROP TABLE i45_collide');
