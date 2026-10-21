DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS event_logs;
DROP TABLE IF EXISTS tickets;
DROP SEQUENCE IF EXISTS ticket_numbers;
DROP TABLE IF EXISTS seats;
DROP TABLE IF EXISTS wristbands;
DROP TABLE IF EXISTS uploads;
CREATE TABLE events (
  id serial PRIMARY KEY,
  title varchar(50)
);
CREATE TABLE event_logs (
  event_id integer,
  message varchar(50)
);
INSERT INTO event_logs (event_id, message) VALUES (1, 'doors open');
CREATE SEQUENCE ticket_numbers START 1000;
CREATE TABLE tickets (
  id integer PRIMARY KEY DEFAULT nextval('ticket_numbers'),
  title varchar(50)
);
CREATE TABLE seats_old (
  id serial PRIMARY KEY,
  label varchar(50)
);
ALTER TABLE seats_old RENAME TO seats;
CREATE TABLE wristbands (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  holder varchar(50)
);
CREATE TABLE uploads (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  filename varchar(50)
);
