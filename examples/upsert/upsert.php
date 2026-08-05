<?php

require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Flight.php';

ActiveRecord\Config::initialize(function ($cfg) {
    $cfg->set_connections(['development' => 'mysql://test:test@127.0.0.1/upsert_test']);
});

// 1. Bulk insert-or-update. On conflict (departure, destination) only `price`
//    is overwritten. Returns the affected-row count.
$affected = Flight::upsert([
    ['departure' => 'Oakland', 'destination' => 'San Diego', 'price' => 99],
    ['departure' => 'Chicago', 'destination' => 'New York', 'price' => 150],
], unique_by: ['departure', 'destination'], update: ['price']);
echo "upsert #1 affected: $affected\n";

// Run again with a new price: the existing rows are updated, not duplicated.
Flight::upsert([
    ['departure' => 'Oakland', 'destination' => 'San Diego', 'price' => 79],
], unique_by: ['departure', 'destination'], update: ['price']);
echo "Oakland->San Diego price is now: " . Flight::find_by_departure('Oakland')?->price . "\n";

// 2. Omit `update` -> every provided column except `created_at` is overwritten on conflict.
Flight::upsert([
    ['departure' => 'Chicago', 'destination' => 'New York', 'price' => 175],
], unique_by: ['departure', 'destination']);

// 3. A single-column `unique_by` may be passed as a string.
Flight::upsert([['id' => 1, 'departure' => 'Oakland', 'destination' => 'San Diego', 'price' => 60]], 'id', ['price']);

// 4. Timestamps are managed automatically when the columns exist: created_at is
//    set once on insert; updated_at is refreshed on every update.
$f = Flight::find_by_departure('Oakland');
echo "created_at={$f?->created_at?->format('c')} updated_at={$f?->updated_at?->format('c')}\n";

// 5. Passing update: [] performs a plain INSERT (it errors on duplicate keys).
Flight::upsert([['departure' => 'Boston', 'destination' => 'Miami', 'price' => 120]], ['departure', 'destination'], []);

// Note: very large arrays are chunked automatically to stay under the database's
// bind-parameter limit — the whole operation runs inside a single transaction.
// The MySQL/MariaDB affected-row count reports 1 per insert and 2 per update.
