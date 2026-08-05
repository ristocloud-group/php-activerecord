CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  email TEXT,
  age INTEGER,
  role TEXT
);
INSERT INTO users (name, email, age, role) VALUES ('Existing', 'taken@example.com', 30, 'member');
