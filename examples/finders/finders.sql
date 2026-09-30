CREATE TABLE widgets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  category TEXT,
  price REAL,
  in_stock INTEGER,
  description TEXT
);
INSERT INTO widgets (name, category, price, in_stock, description) VALUES
  ('Alpha', 'gadgets', 9.99,  1, 'basic gadget'),
  ('Beta',  'gadgets', 19.99, 0, 'deluxe gadget'),
  ('Gamma', 'gizmos',  4.99,  1, 'compact gizmo'),
  ('Delta', 'gizmos',  49.99, 1, 'industrial gizmo');
