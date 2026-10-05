CREATE TABLE widgets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  category TEXT,
  price REAL,
  in_stock INTEGER,
  description TEXT,
  shipping_and_handling INTEGER,
  restocked_at DATETIME
);
INSERT INTO widgets (name, category, price, in_stock, description, shipping_and_handling, restocked_at) VALUES
  ('Alpha', 'gadgets', 9.99,  1, 'basic gadget',     0,    '2026-01-10 08:00:00'),
  ('Beta',  'gadgets', 19.99, 0, 'deluxe gadget',    450,  '2026-04-02 12:00:00'),
  ('Gamma', 'gizmos',  4.99,  1, 'compact gizmo',    0,    '2026-02-20 17:45:00'),
  ('Delta', 'gizmos',  49.99, 1, 'industrial gizmo', 1200, '2026-05-05 09:00:00');
