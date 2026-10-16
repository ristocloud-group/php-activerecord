CREATE TABLE notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  body TEXT
);

-- INT, not INTEGER: a plain primary key, not SQLite's rowid alias, so SQLite
-- never generates it and the application assigns it (room numbers)
CREATE TABLE rooms (
  id INT PRIMARY KEY,
  name TEXT
);
