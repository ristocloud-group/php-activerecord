<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Article.php';

$db = __DIR__ . '/callbacks.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/callbacks.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

function out(string $s): void
{
    echo $s . "\n";
}

out('create (fires before_validation -> before_save -> after_create):');
/** @var Article $a */
$a = Article::create(['title' => 'Hello World', 'body' => 'one two three']);
out("  slug='{$a->slug}' word_count={$a->word_count}");

out('update (fires before_update):');
$a->body = 'now four words here';
$a->save();
out("  word_count={$a->word_count}");

out('halted save (before_save returns false on empty body):');
$b = Article::create(['title' => 'Empty', 'body' => '']);
out('  persisted? ' . ($b->id ? 'yes' : 'no'));

out('destroy (fires before_destroy):');
$a->delete();
