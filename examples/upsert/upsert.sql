-- The (departure, destination) UNIQUE index is the conflict target for upsert.
-- On MySQL/MariaDB the engine uses it automatically; on Postgres/SQLite it is
-- named via the `unique_by` argument.

CREATE TABLE flights (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  departure TEXT NOT NULL,
  destination TEXT NOT NULL,
  price INTEGER,
  created_at datetime,
  updated_at datetime,
  UNIQUE (departure, destination)
);
