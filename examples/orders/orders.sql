CREATE TABLE people (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  state TEXT,
  created_at datetime,
  updated_at datetime
);

CREATE TABLE orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  person_id INTEGER NOT NULL,
  item_name TEXT,
  price decimal(10,2),
  tax decimal(10,2),
  created_at datetime
);

CREATE TABLE payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  person_id INTEGER NOT NULL,
  amount decimal(10,2),
  created_at datetime
);
