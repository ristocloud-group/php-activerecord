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
CREATE TABLE labels (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER,
  name TEXT
);
INSERT INTO labels (task_id, name) VALUES
  (2, 'urgent'),
  (3, 'urgent'),
  (3, 'release');
