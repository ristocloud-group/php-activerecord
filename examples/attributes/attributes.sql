CREATE TABLE companies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, country TEXT);
CREATE TABLE members (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  first_name TEXT,
  last_name TEXT,
  email TEXT,
  password_hash TEXT,
  is_admin INTEGER
);
INSERT INTO companies (name, country) VALUES ('Acme', 'IT');
