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

CREATE TABLE awesome_people(
	id integer not null primary key,
	author_id int,
	is_awesome int default 1
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

-- children of a composite-key has_many: (author_ref, parent_ref) -> authors (author_id, parent_author_id)
CREATE TABLE composite_items (
  id INTEGER NOT NULL PRIMARY KEY,
  author_ref INTEGER,
  parent_ref INTEGER,
  title VARCHAR(20)
);
