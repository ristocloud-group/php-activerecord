-- written for mysql, not tested with any other db
--
-- The (departure, destination) UNIQUE index is the conflict target for upsert.
-- On MySQL/MariaDB the engine uses it automatically; on Postgres/SQLite it is
-- named via the `unique_by` argument.

drop table if exists flights;
create table flights(
  id int not null primary key auto_increment,
  departure varchar(50) not null,
  destination varchar(50) not null,
  price int,
  created_at datetime,
  updated_at datetime,
  unique key uk_route (departure, destination)
);
