DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS tickets;
DROP SEQUENCE IF EXISTS ticket_numbers;
CREATE TABLE events (
  id serial PRIMARY KEY,
  title varchar(50)
);
CREATE SEQUENCE ticket_numbers START 1000;
CREATE TABLE tickets (
  id integer PRIMARY KEY DEFAULT nextval('ticket_numbers'),
  title varchar(50)
);
