CREATE TABLE widgets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  category TEXT,
  price REAL,
  in_stock INTEGER
);
INSERT INTO widgets (name, category, price, in_stock) VALUES
  ('Alpha', 'gadgets', 9.99,  1),
  ('Beta',  'gadgets', 19.99, 0),
  ('Gamma', 'gizmos',  4.99,  1),
  ('Delta', 'gizmos',  49.99, 1);
