CREATE TABLE books (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  author TEXT
);

INSERT INTO books (name, author) VALUES ('How to be Angry', 'Jax');

CREATE TABLE simple_page_visits (
  page TEXT NOT NULL,
  hits INTEGER NOT NULL
);

INSERT INTO simple_page_visits (page, hits) VALUES ('/', 10);
