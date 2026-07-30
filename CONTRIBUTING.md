# Contributing to PHP ActiveRecord #

This repository is a fork maintained by **Ristocloud Group S.r.l.** We maintain it primarily for our own use — fixing bugs and keeping it running on modern PHP and database versions — but contributions are welcome. We are not always able to respond as quickly as we would like, so please do not take delays personally and feel free to remind us by commenting on issues.

### Testing ###

Run the tests with Docker:

```sh
docker compose up -d
docker compose exec tests composer run test
```

If you want to run a subset of all tests:

```
docker compose exec tests vendor/bin/phpunit --filter CacheTest
docker compose exec tests vendor/bin/phpunit --group slow
```

#### Testing against a different version of PHP ####

CI runs the suite across PHP 8.3, 8.4 and 8.5 against MySQL 9.7, MariaDB 11.4, PostgreSQL 18 and SQLite. To reproduce a specific PHP version locally, rebuild the Docker image with the desired version (the default is 8.3):

```sh
docker compose build --build-arg PHP_VERSION=8.5
docker compose up -d
```

Then run the tests:

```sh
docker compose exec tests composer run test
```

You can run static analysis (PHPStan, level 5) with:

```sh
docker compose exec tests composer run analyse
```

#### No Skipped Tests ####

The suite runs with `--fail-on-skipped` (and `--fail-on-warning`, `--fail-on-risky`, `--fail-on-deprecation`), so a skipped test is a **failing build**, not a pass. Tests skip only when a dependency (a database, memcached) is unavailable — and the Docker environment provides all of them, so nothing should skip there. If you see a skipped test, your environment is missing a dependency: bring the full stack up with `docker compose up -d` rather than running PHPUnit against a partial setup.

