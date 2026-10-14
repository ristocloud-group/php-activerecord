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
CREATE TABLE badges (badge_no INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT);
-- column names that are not attribute names: "first name" => first_name, lastName => lastname
CREATE TABLE contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, "first name" TEXT, "lastName" TEXT);
