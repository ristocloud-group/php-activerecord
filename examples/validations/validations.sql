CREATE TABLE print_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, binding TEXT, first_page NUMERIC, last_page NUMERIC);
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  email TEXT,
  age INTEGER,
  role TEXT
);
INSERT INTO users (name, email, age, role) VALUES ('Existing', 'taken@example.com', 30, 'member');
