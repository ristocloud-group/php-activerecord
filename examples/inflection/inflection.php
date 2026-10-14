<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';

// A model that declares no $table_name gets one from its class name: the
// inflector underscores it and pluralizes the last word (Inflector::tableize()).
// The same rules turn a has_many name into a class (humans → Human) and
// resolve the singular builders (build_human/create_human → has_many 'humans').

/**
 * @property int    $id
 * @property string $name
 * @property-read array<int, Human> $humans
 *
 * @method Human build_human(array<string, mixed> $attributes)  has_many builder
 * @method Human create_human(array<string, mixed> $attributes) has_many builder
 */
class Team extends ActiveRecord\Model
{
    public static $has_many = [['humans']];
}

// No $table_name: the table is "humans". (Up to 2.1.0 the irregular man → men
// rule matched the end of any word, so Human read "humen", and build_human()
// found no association.)
/**
 * @property int    $id
 * @property int    $team_id
 * @property string $name
 */
class Human extends ActiveRecord\Model {}

// The escape hatch for a name the rules get wrong: declare the table. Only the
// listed regular words (human, german, roman, …), whole or as the last segment
// after a non-letter (sales_human), skip the irregular rules, so a longer word
// still derives "superhumen". A table created under an old derived name
// (humen, complices) keeps working the same way.
/**
 * @property int    $id
 * @property string $name
 */
class Superhuman extends ActiveRecord\Model
{
    public static $table_name = 'superhumans';
}

// Create a throwaway SQLite database, so this runs with no database server to
// configure.
$db = __DIR__ . '/inflection.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec(<<<'SQL'
    CREATE TABLE teams (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);
    CREATE TABLE humans (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, name TEXT);
    CREATE TABLE superhumans (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);
    SQL);
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

// Derived table names. Compounds of an irregular word keep the irregular
// plural (Fireman, AngryPerson); only matrix/vertex/index take the Latin
// -ices, and only as the last word (up to 2.1.0: Complex → complices, Regex →
// regices, MatrixRow → matrices_row).
echo "tableize():\n";
foreach (['Human', 'Fireman', 'AngryPerson', 'Complex', 'Regex', 'MatrixRow', 'Index'] as $class) {
    printf("  %-11s -> %s\n", $class, ActiveRecord\Inflector::instance()->tableize($class));
}
echo "\n";

// The model reads its derived table, and the has_many builders resolve.
echo 'Human reads table: ' . Human::table()->table . "\n";
/** @var Team $team */
$team = Team::create(['name' => 'Analytical Engine']);
$ada = $team->create_human(['name' => 'Ada']);
$team->create_human(['name' => 'Charles']);
$grace = $team->build_human(['name' => 'Grace']);
echo "created {$ada->name} (id {$ada->id}, team_id {$ada->team_id})\n";
echo "built {$grace->name} (team_id {$grace->team_id}, new record: " . var_export($grace->is_new_record(), true) . ")\n";
echo 'team humans: ' . implode(', ', ActiveRecord\collect(Team::find($team->id)->humans, 'name')) . "\n\n";

// The declared table wins over the derived one.
/** @var Superhuman $clark */
$clark = Superhuman::create(['name' => 'Clark']);
echo 'Superhuman derives "' . ActiveRecord\Inflector::instance()->tableize('Superhuman') . '", reads "' . Superhuman::table()->table . '": ' . $clark->name . "\n";
