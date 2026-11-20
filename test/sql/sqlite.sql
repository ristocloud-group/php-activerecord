CREATE TABLE authors(
	author_id INTEGER NOT NULL PRIMARY KEY,
	parent_author_id INT,
	name VARCHAR  (25) NOT NULL DEFAULT default_name, -- don't touch those spaces
	updated_at datetime,
	created_at datetime,
	some_Date date,
	some_time time,
	some_text text,
	encrypted_password varchar(50),
	mixedCaseField varchar(50)
);

CREATE TABLE books(
	book_id INTEGER NOT NULL PRIMARY KEY,
	Author_Id INT,
	secondary_author_id INT,
	name VARCHAR(50),
	numeric_test VARCHAR(10) DEFAULT '0',
	special NUMERIC(10,2) DEFAULT 0
);

CREATE TABLE book_reviews(
	id INTEGER NOT NULL PRIMARY KEY,
	book_id INT,
	rating INT
);

CREATE TABLE venues (
  Id INTEGER NOT NULL PRIMARY KEY,
  name varchar(50),
  city varchar(60),
  state char(2),
  address varchar(50),
  phone varchar(10) default NULL,
  is_available boolean DEFAULT true,
  is_retired boolean DEFAULT false,
  tier tinyint(2) DEFAULT 5,
  UNIQUE(name,address)
);

CREATE TABLE events (
  id INTEGER NOT NULL PRIMARY KEY,
  venue_id int NOT NULL,
  host_id int NOT NULL,
  title varchar(60) NOT NULL,
  description varchar(50),
  type varchar(15) default NULL
);

CREATE TABLE hosts(
	id INT NOT NULL PRIMARY KEY,
	name VARCHAR(25)
);

CREATE TABLE employees (
	id INTEGER NOT NULL PRIMARY KEY,
	first_name VARCHAR( 255 ) NOT NULL ,
	last_name VARCHAR( 255 ) NOT NULL ,
	nick_name VARCHAR( 255 ) NOT NULL
);

CREATE TABLE positions (
  id INTEGER NOT NULL PRIMARY KEY,
  employee_id int NOT NULL,
  title VARCHAR(255) NOT NULL,
  active SMALLINT NOT NULL
);

CREATE TABLE `rm-bldg`(
    `rm-id` INT NOT NULL,
    `rm-name` VARCHAR(10) NOT NULL,
    `space out` VARCHAR(1) NOT NULL
);

-- no primary key, plain column names: exercises the pk-less write paths
CREATE TABLE pkless_items(
    code INT NOT NULL,
    name VARCHAR(10)
);

-- bigint pk: signed here, so the above-PHP_INT_MAX case (#44) is MySQL-only
CREATE TABLE big_ids(
    id INTEGER NOT NULL PRIMARY KEY,
    note VARCHAR(20)
);

CREATE TABLE awesome_people(
	id integer not null primary key,
	author_id int,
	is_awesome int default 1
);

CREATE TABLE humans(
	id integer not null primary key,
	author_id int,
	name varchar(50)
);

CREATE TABLE amenities(
  `amenity_id` INTEGER NOT NULL PRIMARY KEY,
  `type` varchar(40) NOT NULL DEFAULT ''
);

CREATE TABLE property(
  `property_id` INTEGER NOT NULL PRIMARY KEY
);

CREATE TABLE property_amenities(
  `id` INTEGER NOT NULL PRIMARY KEY,
  `amenity_id` INT NOT NULL,
  `property_id` INT NOT NULL
);

-- column names containing the dynamic-finder separators _and_ / _or_ (#53)
CREATE TABLE swatches(
  `id` INTEGER NOT NULL PRIMARY KEY,
  `black` INT,
  `white` INT,
  `black_and_white` INT,
  `black_or_white` INT,
  `Shade_and_Tone` INT,
  `title` VARCHAR(20)
);

CREATE TABLE users (
    id INTEGER NOT NULL PRIMARY KEY
);

CREATE TABLE newsletters (
    id INTEGER NOT NULL PRIMARY KEY
);

CREATE TABLE user_newsletters (
    id INTEGER NOT NULL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    newsletter_id INTEGER NOT NULL
);

CREATE TABLE valuestore (
  `id` INTEGER NOT NULL PRIMARY KEY,
  `key` varchar(20) NOT NULL DEFAULT '',
  `value` varchar(255) NOT NULL DEFAULT ''
);

CREATE TABLE stories (
  id INTEGER NOT NULL PRIMARY KEY
);

CREATE TABLE news_read_receipts (
  user_id INTEGER NOT NULL,
  story_id INTEGER NOT NULL,
  PRIMARY KEY(user_id, story_id)
);

-- string composite pk, not auto-increment: code is filled by the database when not sent (#41)
CREATE TABLE coded_items (
  owner VARCHAR(20) NOT NULL,
  code VARCHAR(40) NOT NULL DEFAULT (lower(hex(randomblob(16)))),
  name VARCHAR(20),
  PRIMARY KEY(owner, code)
);

-- DATE/DATETIME composite pk: bound in the update/delete WHERE in the column's format (#41)
CREATE TABLE dated_counts (
  day DATE NOT NULL,
  at DATETIME NOT NULL,
  hits INTEGER,
  seen_at DATETIME,
  PRIMARY KEY(day, at)
);

-- children of a composite-key has_many: (author_ref, parent_ref) -> authors (author_id, parent_author_id)
CREATE TABLE composite_items (
  id INTEGER NOT NULL PRIMARY KEY,
  author_ref INTEGER,
  parent_ref INTEGER,
  title VARCHAR(20)
);

-- column names that differ from their attribute names (#92): camelcase (by case only, so mapped on Postgres only), space_name, dash_name, some_day
CREATE TABLE odd_columns (
  id INTEGER NOT NULL PRIMARY KEY,
  `camelCase` VARCHAR(20),
  `space name` VARCHAR(20),
  `dash-name` VARCHAR(20),
  `some day` DATE
);

-- timestamp columns that differ from their attribute names (#92): "created at" is created_at, UPDATED_AT is updated_at (by case only, so mapped on Postgres only)
CREATE TABLE odd_timestamps (
  id INTEGER NOT NULL PRIMARY KEY,
  name VARCHAR(20),
  `created at` DATETIME,
  `UPDATED_AT` DATETIME
);

-- the character sets of these columns are MySQL's (see mysql.sql): here they are text alike
CREATE TABLE charset_words (
  id INTEGER NOT NULL PRIMARY KEY,
  latin1_word VARCHAR(20),
  utf8mb3_word VARCHAR(20),
  utf8mb4_word VARCHAR(20)
);

-- aggregates of the query builder (spec 5.4): DECIMAL has NUMERIC affinity here, so a fraction is stored as a REAL, and REAL is a Column::STRING of raw type real (see AggregateCaster)
CREATE TABLE measurements (
  id INTEGER NOT NULL PRIMARY KEY,
  qty INT,
  big BIGINT,
  amount DECIMAL(20,2),
  ratio DOUBLE,
  single REAL,
  label VARCHAR(20)
);

-- soft delete (sub-project 3a), trash_* tables: trash_pkless has no primary key, trash_dated keeps its flag in a DATE, trash_wrongs in a VARCHAR
CREATE TABLE trash_posts (
  id INTEGER NOT NULL PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  title VARCHAR(50),
  draft INT NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  updated_at DATETIME NULL
);

CREATE TABLE trash_comments (
  id INTEGER NOT NULL PRIMARY KEY,
  trash_post_id INT,
  body VARCHAR(50),
  deleted_at DATETIME NULL
);

CREATE TABLE trash_tags (
  id INTEGER NOT NULL PRIMARY KEY,
  name VARCHAR(20),
  deleted_at DATETIME NULL
);

CREATE TABLE trash_taggings (
  id INTEGER NOT NULL PRIMARY KEY,
  trash_post_id INT,
  trash_tag_id INT,
  deleted_at DATETIME NULL
);

CREATE TABLE trash_votes (
  id INTEGER NOT NULL PRIMARY KEY,
  trash_comment_id INT,
  value INT,
  deleted_at DATETIME NULL
);

CREATE TABLE trash_dated (
  id INTEGER NOT NULL PRIMARY KEY,
  name VARCHAR(20),
  removed_on DATE NULL
);

CREATE TABLE trash_wrongs (
  id INTEGER NOT NULL PRIMARY KEY,
  name VARCHAR(20),
  deleted_at VARCHAR(20) NULL
);

CREATE TABLE trash_pkless (
  code INT NOT NULL,
  deleted_at DATETIME NULL
);
