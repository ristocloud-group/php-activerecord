CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);
CREATE TABLE products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id INTEGER,
  name TEXT,
  price REAL,
  secret_cost REAL
);
INSERT INTO categories (name) VALUES ('Tools');
