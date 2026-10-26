CREATE TABLE print_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, binding TEXT, first_page NUMERIC, last_page NUMERIC);
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  email TEXT,
  age INTEGER,
  role TEXT
);
CREATE TABLE parcels (id INTEGER PRIMARY KEY AUTOINCREMENT, weight_kg TEXT);
INSERT INTO users (name, email, age, role) VALUES ('Existing', 'taken@example.com', 30, 'member');
CREATE TABLE shipments (id INTEGER PRIMARY KEY AUTOINCREMENT, tracking_number TEXT);
CREATE TABLE club_members (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, nickname TEXT, badge TEXT);
CREATE TABLE hall_seats (hall TEXT NOT NULL, seat TEXT NOT NULL, guest TEXT, PRIMARY KEY (hall, seat));
INSERT INTO hall_seats (hall, seat, guest) VALUES ('A', '1', 'Ada'), ('A', '2', 'Bo');
