<?php

/**
 * In order to run these unit tests, you need to install the required packages using Composer:
 *
 *    $ composer install
 *
 * After that you can run the tests by invoking the local PHPUnit
 *
 * To run all test simply use:
 *
 *    $ vendor/bin/phpunit
 *
 * Or run a single test file by specifying its path:
 *
 *    $ vendor/bin/phpunit test/InflectorTest.php
 *
 **/

use ActiveRecord\Config;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

require_once 'vendor/autoload.php';
require_once __DIR__ . '/model_autoloader.php';

require_once 'SnakeCase_PHPUnit_Framework_TestCase.php';

require_once 'DatabaseTest.php';
require_once 'AdapterTest.php';
require_once 'UpsertTest.php';

require_once __DIR__ . '/../../ActiveRecord.php';

// whether or not to show warnings when Log or Memcache is missing
$GLOBALS['show_warnings'] = true;

if (getenv('LOG') !== 'false') {
    DatabaseTest::$log = true;
}

ActiveRecord\Config::initialize(function (Config $cfg) {
    $cfg->set_connections([
        'mysql'   => getenv('PHPAR_MYSQL') ?: 'mysql://test:test@127.0.0.1/test',
        'mariadb' => getenv('PHPAR_MARIADB') ?: 'mysql://test:test@127.0.0.1/test',
        'pgsql'   => getenv('PHPAR_PGSQL') ?: 'pgsql://test:test@127.0.0.1/test',
        'sqlite'  => getenv('PHPAR_SQLITE') ?: 'sqlite://test.db']);

    $cfg->set_default_connection(getenv('PHPAR_CONNECTION') ?: 'mysql');

    $logger = new Logger('tests');
    $logger->pushHandler(new StreamHandler(dirname(__FILE__) . '/../log/query.log', Level::Debug));
    $cfg->set_logger($logger);

    if ($GLOBALS['show_warnings']  && !isset($GLOBALS['show_warnings_done'])) {
        if (!extension_loaded('memcached')) {
            echo "(Cache Tests will be skipped, Memcache not found.)\n";
        }
    }

    date_default_timezone_set('UTC');

    $GLOBALS['show_warnings_done'] = true;
});

// Make the adapter under test explicit in the run output and the query log, so
// it is visually verifiable which connection — and therefore which adapter
// class — the behavioral suite is actually exercising (guards against silently
// running every test on MySQL). Derived from the default connection string
// without opening a connection, so DB-less unit tests still run offline.
if (!isset($GLOBALS['adapter_banner_done'])) {
    $default_connection = Config::instance()->get_default_connection();
    $connection_string = Config::instance()->get_default_connection_string();
    $protocol = (string) parse_url($connection_string, PHP_URL_SCHEME);
    $adapter_class = 'ActiveRecord\\' . ucwords($protocol) . 'Adapter';
    $banner = sprintf('php-activerecord test suite → connection "%s" (%s → %s)', $default_connection, $protocol, $adapter_class);

    // Credentials are printed in full on purpose — this is the test harness.
    echo ">>> {$banner}\n";
    echo ">>>     connection string: {$connection_string}\n";
    if ($logger = Config::instance()->get_logger()) {
        $logger->info($banner . ' [' . $connection_string . ']');
    }

    $GLOBALS['adapter_banner_done'] = true;
}

error_reporting(E_ALL);
