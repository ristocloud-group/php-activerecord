-- Customers and their invoices. SQLite has no exact DECIMAL (it stores one as a REAL), so the
-- amounts are whole cents in an INTEGER column: the builder's aggregates of an integer column are
-- exact on every database, and so are those of a DECIMAL column on MySQL, MariaDB and Postgres.
CREATE TABLE customers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  country TEXT
);

CREATE TABLE invoices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL,
  number TEXT NOT NULL,
  status TEXT NOT NULL,
  total_cents INTEGER NOT NULL,
  issued_on DATE NOT NULL,
  paid_on DATE,
  note TEXT,
  created_at datetime,
  updated_at datetime
);

INSERT INTO customers (name, country) VALUES
  ('Acme',     'IT'),
  ('Globex',   'DE'),
  ('Initech',  'IT'),
  ('Umbrella', 'US');

-- The seed rows have no created_at / updated_at: the writes of the example fill them.
INSERT INTO invoices (customer_id, number, status, total_cents, issued_on, paid_on, note) VALUES
  (1, 'INV-001', 'paid', 12000, '2026-01-10', '2026-01-15', NULL),
  (1, 'INV-002', 'open',  8000, '2026-02-03', NULL,         'rush'),
  (2, 'INV-003', 'paid', 20000, '2026-02-14', '2026-02-20', NULL),
  (2, 'INV-004', 'void',  5000, '2026-03-01', NULL,         'duplicate'),
  (3, 'INV-005', 'open', 20000, '2026-03-09', NULL,         NULL),
  (3, 'INV-006', 'open',  7001, '2026-03-20', NULL,         'rush');
