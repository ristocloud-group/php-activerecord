CREATE TABLE widgets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  category TEXT,
  price REAL,
  in_stock INTEGER,
  description TEXT,
  shipping_and_handling INTEGER
);
INSERT INTO widgets (name, category, price, in_stock, description, shipping_and_handling) VALUES
  ('Alpha', 'gadgets', 9.99,  1, 'basic gadget',     0),
  ('Beta',  'gadgets', 19.99, 0, 'deluxe gadget',    450),
  ('Gamma', 'gizmos',  4.99,  1, 'compact gizmo',    0),
  ('Delta', 'gizmos',  49.99, 1, 'industrial gizmo', 1200);
