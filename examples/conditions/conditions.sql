CREATE TABLE tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  flag INTEGER
);
INSERT INTO tasks (name, flag) VALUES
  ('write docs',   1),
  ('review PR',    2),
  ('cut release',  1),
  ('triage inbox', NULL);
