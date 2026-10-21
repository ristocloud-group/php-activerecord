<?php

// Primary-key sequences in models.
//
// Sequences are a Postgres-only concept in this library
// (Connection::supports_sequences() is true only for the pgsql adapter).
// This example shows:
//
//   1. on adapters WITHOUT sequences (the SQLite default here, same for
//      MySQL/MariaDB) a declared static $sequence is harmlessly ignored and
//      the pk comes from the regular auto-increment;
//   2. on SQLite, which primary keys get that auto-increment: only the rowid
//      alias; any other pk keeps the value a record is created with;
//   3. on Postgres, a pk the database fills (serial under any sequence name,
//      IDENTITY, a uuid default) read back with INSERT … RETURNING, the
//      {table}_{pk}_seq convention for a pk without a default, no sequence
//      (null) for a table without a pk, and an explicit static $sequence;
//      the last two pull nextval() for the pk in the INSERT.
//
// Part 3 needs a reachable Postgres and is skipped otherwise: set PHPAR_PGSQL
// to a pgsql:// URL (this repo's Docker setup already does).

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Note.php';
require_once __DIR__ . '/models/Room.php';
require_once __DIR__ . '/models/Event.php';
require_once __DIR__ . '/models/EventLog.php';
require_once __DIR__ . '/models/Ticket.php';
require_once __DIR__ . '/models/Seat.php';
require_once __DIR__ . '/models/Wristband.php';
require_once __DIR__ . '/models/Upload.php';
require_once __DIR__ . '/models/Gate.php';

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

// --- 2. Which primary keys SQLite generates ---

// SQLite generates a pk only for its rowid alias: a lone INTEGER PRIMARY KEY
// on an ordinary rowid table, not declared DESC inline, like notes.id. Any
// other pk (INT, BIGINT, a composite key, a WITHOUT ROWID table) holds the
// value you insert, and the model keeps it: rooms.id is INT PRIMARY KEY,
// numbered by hand. (Up to 2.1.0 the model took the internal rowid as its id,
// so a later save() or delete() hit the row whose id equals that rowid:
// another room, or none.)
out('');
/** @var Room $room */
$room = Room::create(['id' => 101, 'name' => 'Lecture hall']);
out('created room id:             ' . $room->id . ' (as given: rooms.id is INT PRIMARY KEY)');

$room->name = 'Lecture hall A';
$room->save();
out('Room::find(101)->name:       ' . Room::find(101)->name);

$note = Note::create(['body' => 'no id given']);
out('created note id:             ' . $note->id . ' (the rowid: notes.id is INTEGER PRIMARY KEY)');

// --- 3. Adapter with sequences (Postgres) ---

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

// A serial pk: the library reads its sequence from the column's default
// (events_id_seq, which is also the {table}_{pk}_seq convention name) — Event
// declares nothing sequence-related.
out("get_sequence_name('events', 'id'): " . $pg->get_sequence_name('events', 'id'));
out('Event::table()->sequence:          ' . (Event::table()->sequence ?? '(null)'));
out("next_sequence_value(...):          " . ($pg->next_sequence_value('events_id_seq') ?? '(null)'));

// The database fills the pk, so the INSERT leaves it out and returns the
// stored value (RETURNING), which the model takes as its id.
$event = Event::create(['title' => 'PHP Meetup']);
out('created event id:                  ' . $event->id);
out('  SQL: ' . Event::table()->last_sql);

// The same holds whatever the database fills the pk with. A renamed table
// keeps its old sequence (seats_old_id_seq, not seats_id_seq)...
$seat = Seat::create(['label' => 'A1']);
out('Seat::table()->sequence:           ' . (Seat::table()->sequence ?? '(null)'));
out('created seat id:                   ' . $seat->id);

// ...an IDENTITY has its own sequence (GENERATED ALWAYS: the database alone
// numbers it, an explicit id is rejected by Postgres)...
$wristband = Wristband::create(['holder' => 'Ada']);
out('Wristband::table()->sequence:      ' . (Wristband::table()->sequence ?? '(null)'));
out('created wristband id:              ' . $wristband->id);

// ...and a uuid default has no sequence at all.
/** @var Upload $upload */
$upload = Upload::create(['filename' => 'slides.pdf']);
out('Upload::table()->sequence:         ' . (Upload::table()->sequence ?? '(null)'));
out('created upload id:                 ' . $upload->id . ' (Upload::find() agrees: ' . var_export(Upload::find($upload->id)->filename, true) . ')');

// A pk without a default: nothing names its sequence, so the library falls
// back to the {table}_{pk}_seq convention (gates_id_seq, created with START 10)
// and the INSERT pulls nextval() from it.
$gate = Gate::create(['name' => 'North']);
out('Gate::table()->sequence:           ' . (Gate::table()->sequence ?? '(null)'));
out('created gate id:                   ' . $gate->id);
out('  SQL: ' . Gate::table()->last_sql);

// No primary key, no convention sequence: event_logs has no pk column to name
// a sequence after, so the model loads cleanly and Table::$sequence stays
// null. (Before this was fixed, loading it raised "Undefined array key 0" and
// invented a bogus "event_logs__seq".)
/** @var EventLog $log */
$log = EventLog::first();
out('EventLog::table()->pk:             [' . implode(', ', EventLog::table()->pk) . ']');
out('EventLog::table()->sequence:       ' . (EventLog::table()->sequence ?? '(null)'));
out('EventLog::first()->message:        ' . $log->message);

// A declared sequence: Ticket declares static $sequence = 'ticket_numbers'
// (created with START 1000), so ids begin at 1000. A declaration always wins:
// the INSERT pulls nextval() for the pk instead of using RETURNING.
$ticket = Ticket::create(['title' => 'Front row']);
out('Ticket::table()->sequence:         ' . (Ticket::table()->sequence ?? '(null)'));
out('created ticket id:                 ' . $ticket->id . " (from nextval('ticket_numbers'))");
out('  SQL: ' . Ticket::table()->last_sql);

// Assigning the pk yourself bypasses the sequence entirely — and does NOT
// advance it: the next sequence-driven insert continues where it left off.
Ticket::create(['id' => 5000, 'title' => 'Comp (explicit pk)']);
$next = Ticket::create(['title' => 'Balcony']);
out('after an explicit pk 5000, next sequence id is still ' . $next->id);
