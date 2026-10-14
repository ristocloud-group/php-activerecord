DROP TABLE IF EXISTS pg_widgets;
DROP TABLE IF EXISTS pg_widget_logs;
CREATE TABLE pg_widgets (
  id serial PRIMARY KEY,
  name varchar(20),
  category varchar(20),
  price numeric(8, 2),
  in_stock int
);
INSERT INTO pg_widgets (name, category, price, in_stock) VALUES
  ('Alpha', 'gadgets', 9.99,  1),
  ('Beta',  'gadgets', 19.99, 1),
  ('Gamma', 'gizmos',  4.99,  1),
  ('Delta', 'gizmos',  49.99, 1);
CREATE TABLE pg_widget_logs (
  widget_id int,
  message varchar(50)
);
INSERT INTO pg_widget_logs (widget_id, message) VALUES
  (1, 'restocked'),
  (2, 'restocked'),
  (3, 'restocked')
