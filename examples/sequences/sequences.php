<?php

// Primary-key sequences in models.
//
// Sequences are a Postgres-only concept in this library
// (Connection::supports_sequences() is true only for the pgsql adapter).
// This example shows both sides:
//
//   1. on adapters WITHOUT sequences (the SQLite default here, same for
//      MySQL/MariaDB) a declared static $sequence is harmlessly ignored and
//      the pk comes from the regular auto-increment;
//   2. on Postgres, the {table}_{pk}_seq convention, an explicit
//      static $sequence for a non-convention name, and how an insert pulls
//      nextval() for the pk.
//
// Part 2 needs a reachable Postgres and is skipped otherwise: set PHPAR_PGSQL
// to a pgsql:// URL (this repo's Docker setup already does).

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Note.php';
require_once __DIR__ . '/models/Event.php';
require_once __DIR__ . '/models/Ticket.php';

$db = __DIR__ . '/sequences.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/sequences.sql'));
$pdo = null;

$pgsql_url = getenv('PHPAR_PGSQL') ?: '';

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db, $pgsql_url) {
    $connections = ['development' => 'sqlite://unix(' . $db . ')'];

    if ('' !== $pgsql_url) {
        $connections['pgsql'] = $pgsql_url;
    }

    $cfg->set_connections($connections);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

function out(string $s): void
{
    echo $s . "\n";
}

// --- 1. Adapter without sequences (SQLite; MySQL/MariaDB behave the same) ---

$conn = Note::connection();
out('supports_sequences():        ' . var_export($conn->supports_sequences(), true));

// Note declares static $sequence = 'notes_id_seq', but on a non-sequence
// adapter the declaration never reaches the Table...
out('Note::$sequence declared as: ' . var_export(Note::$sequence, true));
out('Note::table()->sequence:     ' . var_export(Note::table()->sequence, true));
out('next_sequence_value():       ' . var_export($conn->next_sequence_value('notes_id_seq'), true));

// ...and the insert succeeds through the plain auto-increment pk.
$note = Note::create(['body' => 'sequences are ignored here']);
out('created note id:             ' . $note->id . ' (auto-increment, no sequence involved)');

// --- 2. Adapter with sequences (Postgres) ---

if ('' === $pgsql_url) {
    out('');
    out('Postgres half skipped: set PHPAR_PGSQL to a pgsql:// URL to run it.');
    exit(0);
}

try {
    $pg = ActiveRecord\ConnectionManager::get_connection('pgsql');
} catch (ActiveRecord\DatabaseException $e) {
    out('');
    out('Postgres half skipped: connection failed (' . strtok($e->getMessage(), "\n") . ')');
    exit(0);
}

$statements = array_filter(array_map('trim', explode(';', (string) file_get_contents(__DIR__ . '/sequences-pgsql.sql'))));
foreach ($statements as $statement) {
    $pg->query($statement);
}

out('');
out('supports_sequences():              ' . var_export($pg->supports_sequences(), true));

// Convention: a serial pk owns {table}_{pk}_seq, introspected automatically —
// Event declares nothing sequence-related.
out("get_sequence_name('events', 'id'): " . $pg->get_sequence_name('events', 'id'));
out('Event::table()->sequence:          ' . (Event::table()->sequence ?? '(null)'));
out("next_sequence_value(...):          " . ($pg->next_sequence_value('events_id_seq') ?? '(null)'));

// The INSERT itself pulls nextval('events_id_seq') for the pk, and the model
// reads the assigned value back through the sequence.
$event = Event::create(['title' => 'PHP Meetup']);
out('created event id:                  ' . $event->id);
out('  SQL: ' . Event::table()->last_sql);

// Non-convention name: Ticket declares static $sequence = 'ticket_numbers'
// (created with START 1000), so ids begin at 1000.
$ticket = Ticket::create(['title' => 'Front row']);
out('Ticket::table()->sequence:         ' . (Ticket::table()->sequence ?? '(null)'));
out('created ticket id:                 ' . $ticket->id . " (from nextval('ticket_numbers'))");

// Assigning the pk yourself bypasses the sequence entirely — and does NOT
// advance it: the next sequence-driven insert continues where it left off.
Ticket::create(['id' => 5000, 'title' => 'Comp (explicit pk)']);
$next = Ticket::create(['title' => 'Balcony']);
out('after an explicit pk 5000, next sequence id is still ' . $next->id);
