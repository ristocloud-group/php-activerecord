-- Orders, some of them trashed: a trashed row keeps the time it was trashed in deleted_at, a live row has NULL.
CREATE TABLE orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  number TEXT NOT NULL,
  deleted_at datetime
);

INSERT INTO orders (number, deleted_at) VALUES
  ('ORD-001', NULL),
  ('ORD-002', '2026-03-01 10:00:00'),
  ('ORD-003', NULL),
  ('ORD-004', '2026-03-02 11:30:00');
