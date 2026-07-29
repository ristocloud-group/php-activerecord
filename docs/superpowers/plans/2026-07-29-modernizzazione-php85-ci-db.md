# Modernizzazione php-activerecord (PHP 8.5 / CI / PHPUnit 12 / DB) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Portare il fork `zamzar/php-activerecord` su PHP 8.5 (floor 8.3), PHPUnit 12, GitHub Actions e sui database MariaDB 11.4 / MySQL 9.7 / PostgreSQL 18, mantenendo intatta la DSL di test snake_case.

**Architecture:** Migrazione multi-asse eseguita in fasi bottom-up, un PR per task, per tenere un segnale verde continuo. Prima le fondamenta (immagini Docker + client DB recenti), poi le dipendenze, i fix runtime, la migrazione PHPUnit, il supporto multi-DB, la nuova CI e infine gli strumenti di qualità. Sviluppo in TDD: ogni modifica di codice parte da un test rosso; le fasi di sola configurazione usano gate eseguibili + test di integrazione reali.

**Tech Stack:** PHP 8.3/8.4/8.5, PHPUnit 12, monolog 3 / psr-log 3, PHPStan livello 5, Docker Compose v2, GitHub Actions (`shivammathur/setup-php`), MySQL 9.7, MariaDB 11.4, PostgreSQL 18, SQLite, memcached.

## Global Constraints

- **PHP floor: `^8.3`** — nessun codice o dipendenza può richiedere < 8.3; il target runtime è 8.5.
- **PHPUnit: `^12`** — non 13 (13 richiede PHP 8.4+, incompatibile con la riga di matrice 8.3).
- **monolog: `^3`**, **psr/log: `^3`**.
- **DSL snake_case preservata** — i 559 test restano scritti con `set_up`/`tear_down` e assert snake_case; non convertire a camelCase.
- **API pubblica snake_case invariata** — nessuna rinomina di metodi/opzioni pubbliche.
- **MySQL first** — a fronte di divergenze MySQL vs MariaDB, MySQL vince.
- **`--fail-on-skipped` attivo** — nessun test deve skippare nell'ambiente Docker.
- **Versione target: 2.0.0** (breaking: drop PHP < 8.3).
- **`ActiveRecord.php` è un manifest manuale di `require`** — non esiste autoloader PSR-4 per la libreria; nuovi file `lib/` vanno aggiunti a mano (non rilevante qui: non si creano file `lib/`).
- **Un PR per task**, ognuno con la propria chiusura verde e commit.

---

## File Structure

File toccati o creati, per responsabilità:

- `docker-compose.yml` → rinominato `compose.yaml` — orchestrazione servizi (PHP + 4 DB + memcached).
- `Dockerfile` — immagine PHP di test; base bump a 8.3, aggiunta pcov (Task 7).
- `composer.json` — vincoli dipendenze, script.
- `lib/Model.php` — un fix di firma runtime (`set_relationship_from_eager_load`).
- `test/helpers/config.php` — registrazione connessioni (aggiunta `mariadb`), fix monolog `Level`, fix `error_reporting`.
- `test/helpers/SnakeCase_PHPUnit_Framework_TestCase.php` — wrapper assert, adattamento PHPUnit 12.
- `test/MariadbAdapterTest.php` (create) — batteria adapter contro MariaDB.
- `test/ConnectionMariadbIntegrationTest.php` (create) — test di connettività MariaDB (Task 1).
- `phpunit.xml` — riscrittura schema PHPUnit 12 + sezione `<source>` coverage.
- `.github/workflows/ci.yml` (create) — pipeline CI.
- `.github/dependabot.yml` (create) — aggiornamenti automatici.
- `phpstan.neon` (create) + `phpstan-baseline.neon` (create) — analisi statica livello 5 + baseline.
- `.circleci/` (delete) — CI dismessa.
- `CLAUDE.md`, `RELEASES.md`, `ActiveRecord.php` — documentazione e version bump.

---

## Task 1: Fondamenta ambiente locale (Docker Compose v2 + immagini DB nuove + MariaDB)

Bump della base PHP a 8.3 (necessario: il client `pdo_mysql` recente serve per l'auth `caching_sha2` di MySQL 9.7), Compose v2, immagini DB aggiornate e nuovo backend MariaDB con test di integrazione di connettività.

**Files:**
- Rename: `docker-compose.yml` → `compose.yaml`
- Modify: `Dockerfile:1` (`ARG PHP_VERSION=7.4` → `8.3`)
- Modify: `test/helpers/config.php:44-47` (aggiunta connessione `mariadb`)
- Create: `test/ConnectionMariadbIntegrationTest.php`

**Interfaces:**
- Consumes: nulla (primo task).
- Produces:
  - Variabile ambiente `PHPAR_MARIADB` = `mysql://phpar:secret@mariadb/phpar_test`.
  - Connessione registrata con nome `mariadb` in `Config` (protocollo `mysql`, adapter `MysqlAdapter`).
  - Servizi Compose: `mysql` (mysql:9.7), `mariadb` (mariadb:11.4), `postgres` (postgres:18), `memcached`, `tests`.

> **Nota transitoria:** in questo task la libreria gira ancora su PHPUnit 9 sotto PHP 8.3, che può emettere deprecation. Il gate di questo task NON usa i flag stretti (`--fail-on-warning`): usa `vendor/bin/phpunit` semplice. Il gate stretto (`composer run test`) torna verde in Task 4.

- [ ] **Step 1: Rinominare e convertire il compose a v2**

Rinomina `docker-compose.yml` in `compose.yaml`, rimuovi la chiave `version:`, aggiorna immagini, aggiungi il servizio `mariadb` e la env `PHPAR_MARIADB`. Contenuto completo:

```yaml
services:
  tests:
    build:
      context: .
      args:
        - PHP_VERSION=8.3
    command: tail -f /dev/null
    environment:
      - PHPAR_PGSQL=pgsql://phpar:secret@postgres/phpar_test
      - PHPAR_MYSQL=mysql://phpar:secret@mysql/phpar_test
      - PHPAR_MARIADB=mysql://phpar:secret@mariadb/phpar_test
      - PHPAR_MEMCACHED=memcached
    depends_on:
      mysql:
        condition: service_healthy
      mariadb:
        condition: service_healthy
      postgres:
        condition: service_healthy
  mysql:
    image: mysql:9.7
    environment:
      - MYSQL_USER=phpar
      - MYSQL_PASSWORD=secret
      - MYSQL_DATABASE=phpar_test
      - MYSQL_ROOT_PASSWORD=secret
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-psecret"]
      interval: 5s
      retries: 10
      timeout: 5s
  mariadb:
    image: mariadb:11.4
    environment:
      - MARIADB_USER=phpar
      - MARIADB_PASSWORD=secret
      - MARIADB_DATABASE=phpar_test
      - MARIADB_ROOT_PASSWORD=secret
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      retries: 10
      timeout: 5s
  postgres:
    image: postgres:18
    environment:
      - POSTGRES_DB=phpar_test
      - POSTGRES_USER=phpar
      - POSTGRES_PASSWORD=secret
    healthcheck:
      test: ["CMD", "pg_isready", "-U", "phpar"]
      interval: 5s
      retries: 10
      timeout: 5s
  memcached:
    image: memcached:1.6
```

- [ ] **Step 2: Bump base image nel Dockerfile**

In `Dockerfile:1` cambia `ARG PHP_VERSION=7.4` in `ARG PHP_VERSION=8.3`.

- [ ] **Step 3: Registrare la connessione `mariadb` in config.php**

In `test/helpers/config.php`, dentro `set_connections(array(...))`, aggiungi la riga `mariadb`:

```php
	$cfg->set_connections(array(
		'mysql'   => getenv('PHPAR_MYSQL')   ?: 'mysql://test:test@127.0.0.1/test',
		'mariadb' => getenv('PHPAR_MARIADB') ?: 'mysql://test:test@127.0.0.1/test',
		'pgsql'   => getenv('PHPAR_PGSQL')   ?: 'pgsql://test:test@127.0.0.1/test',
		'sqlite'  => getenv('PHPAR_SQLITE')  ?: 'sqlite://test.db'));
```

- [ ] **Step 4: Scrivere il test di integrazione MariaDB (rosso)**

Create `test/ConnectionMariadbIntegrationTest.php`. Verifica che la connessione `mariadb` si apra, usi `MysqlAdapter` e sappia introspezionare le tabelle caricate da `test/sql/mysql.sql`:

```php
<?php

class ConnectionMariadbIntegrationTest extends DatabaseTest
{
	public function set_up($connection_name=null)
	{
		parent::set_up('mariadb');
	}

	public function test_mariadb_connection_uses_mysql_adapter()
	{
		$this->assert_is_a('ActiveRecord\\MysqlAdapter', $this->conn);
	}

	public function test_mariadb_can_introspect_authors_table()
	{
		$columns = $this->conn->columns('authors');
		$this->assert_array_has_key('author_id', $columns);
	}
}
```

- [ ] **Step 5: Avviare i servizi e verificare la salute**

```bash
docker compose up -d
docker compose ps
```
Expected: `mysql`, `mariadb`, `postgres` in stato `healthy`.

- [ ] **Step 6: Eseguire il test di integrazione (deve passare)**

```bash
docker compose exec tests vendor/bin/phpunit test/ConnectionMariadbIntegrationTest.php
```
Expected: PASS (2 test). Se fallisce sull'auth MySQL 9.7, verifica che l'immagine `php:8.3` abbia `pdo_mysql` con supporto `caching_sha2` (default sì).

- [ ] **Step 7: Verificare che la suite gira ancora (gate rilassato)**

```bash
docker compose exec tests vendor/bin/phpunit
```
Expected: la suite esegue e i test passano (eventuali deprecation PHPUnit9-su-PHP8.3 sono attese e verranno risolte in Task 2–4; qui NON si usano i flag stretti).

- [ ] **Step 8: Commit**

```bash
git add compose.yaml Dockerfile test/helpers/config.php test/ConnectionMariadbIntegrationTest.php
git rm docker-compose.yml
git commit -m "Moves the test stack to Compose v2 with MySQL 9.7, MariaDB 11.4, and Postgres 18"
```

---

## Task 2: Aggiornamento dipendenze (composer + monolog 3)

Alza i vincoli a PHP 8.3 / PHPUnit 12 / monolog 3 / psr-log 3, introduce PHPStan, rimuove PHPCompatibility e `spatie/phpunit-watcher`, e adegua `config.php` alle API di monolog 3.

**Files:**
- Modify: `composer.json`
- Modify: `test/helpers/config.php` (logger monolog 3)

**Interfaces:**
- Consumes: base image 8.3 (Task 1).
- Produces: toolchain PHPUnit 12 + PHPStan disponibile per i task successivi.

- [ ] **Step 1: Aggiornare composer.json**

Sostituisci le sezioni `require`, `require-dev` e `scripts`:

```json
    "require": {
        "php": "^8.3",
        "psr/log": "^3.0"
    },
    "require-dev": {
        "monolog/monolog": "^3.0",
        "phpunit/phpunit": "^12.0",
        "phpstan/phpstan": "^2.0",
        "squizlabs/php_codesniffer": "^3.10"
    },
    "autoload": {
        "files": [ "ActiveRecord.php" ]
    },
    "scripts": {
        "test": "phpunit --fail-on-risky --fail-on-warning --fail-on-skipped --testdox --colors=always --log-junit junit.xml",
        "analyse": "phpstan analyse"
    }
```

Note: rimossi `phpcompatibility/php-compatibility`, `spatie/phpunit-watcher`, gli script `check-compatibility`, `post-install-cmd`, `post-update-cmd`.

- [ ] **Step 2: Adeguare il logger di test a monolog 3**

In `test/helpers/config.php`, `Logger::DEBUG` è stato rimosso in monolog 3. Aggiorna l'import e l'istanza:

Cambia l'header degli `use`:
```php
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
```
E la riga dell'handler:
```php
    $logger->pushHandler(new StreamHandler(dirname(__FILE__) . '/../log/query.log', Level::Debug));
```

- [ ] **Step 3: Ricostruire l'immagine e reinstallare le dipendenze**

```bash
docker compose build tests
docker compose up -d
docker compose exec tests composer update
```
Expected: risoluzione senza conflitti; `phpunit/phpunit` a 12.x, `monolog/monolog` a 3.x in `composer.lock`.

- [ ] **Step 4: Verificare che PHPUnit 12 sia attivo**

```bash
docker compose exec tests vendor/bin/phpunit --version
```
Expected: `PHPUnit 12.x`.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock test/helpers/config.php
git commit -m "Raises the toolchain to PHP 8.3, PHPUnit 12, and monolog 3"
```

---

## Task 3: Fix runtime PHP 8.5

Corregge le deprecation runtime su 8.4/8.5 con approccio TDD. Superficie nota: una firma in `lib/Model.php` e l'`error_reporting` nel bootstrap di test.

**Files:**
- Modify: `lib/Model.php:1220`
- Modify: `test/helpers/config.php` (ultima riga `error_reporting`)
- Test: `test/ActiveRecordTest.php` (nuovo metodo) — o file esistente più pertinente all'eager load

**Interfaces:**
- Consumes: toolchain PHPUnit 12 (Task 2).
- Produces: `Model::set_relationship_from_eager_load(?Model $model, $name)` — firma corretta senza optional-prima-di-required.

- [ ] **Step 1: Scrivere il test che copre l'eager load con relazione nulla (rosso su deprecation)**

Il chiamante `Relationship.php:201` passa `null`. Aggiungi in `test/ActiveRecordTest.php` un test che esercita esplicitamente il percorso, così la firma è coperta:

```php
	public function test_set_relationship_from_eager_load_accepts_null_model()
	{
		$book = Book::first();
		// non deve emettere deprecation e deve accettare null come primo argomento
		$book->set_relationship_from_eager_load(null, 'author');
		$this->assert_null($book->author);
	}
```

- [ ] **Step 2: Eseguire il test per vederlo fallire**

```bash
docker compose exec tests vendor/bin/phpunit --filter test_set_relationship_from_eager_load_accepts_null_model
```
Expected: FAIL/WARNING — deprecation "Optional parameter $model declared before required parameter $name" (e su 8.4 "implicitly nullable").

- [ ] **Step 3: Correggere la firma in lib/Model.php**

In `lib/Model.php:1220` sostituisci:
```php
	public function set_relationship_from_eager_load(Model $model=null, $name)
```
con:
```php
	public function set_relationship_from_eager_load(?Model $model, $name)
```
(I tre chiamanti in `Relationship.php:191/193/201` passano sempre entrambi gli argomenti, quindi rimuovere il default è sicuro.)

- [ ] **Step 4: Correggere `error_reporting` nel bootstrap (E_STRICT deprecato in 8.4)**

Nell'ultima riga di `test/helpers/config.php` sostituisci:
```php
error_reporting(E_ALL | E_STRICT);
```
con:
```php
error_reporting(E_ALL);
```

- [ ] **Step 5: Eseguire il test mirato (deve passare)**

```bash
docker compose exec tests vendor/bin/phpunit --filter test_set_relationship_from_eager_load_accepts_null_model
```
Expected: PASS, nessuna deprecation.

- [ ] **Step 6: Eseguire l'intera suite per scovare altre deprecation 8.5**

```bash
docker compose exec tests vendor/bin/phpunit --fail-on-warning
```
Expected: verde. Se emergono altre deprecation, per ognuna: scrivi prima un test che la isola (unit sul percorso, funzionale se passa per `Model`/`Table`), poi il fix minimale, poi ri-esegui.

- [ ] **Step 7: Commit**

```bash
git add lib/Model.php test/helpers/config.php test/ActiveRecordTest.php
git commit -m "Fixes PHP 8.4/8.5 runtime deprecations in the eager-load signature and test bootstrap"
```

---

## Task 4: Migrazione PHPUnit 12 (schema + wrapper) e ripristino gate stretto

Riscrive `phpunit.xml` allo schema moderno e adatta il wrapper snake_case. Nessun rename di assert è necessario (verificato: la suite non usa `assertRegExp`/`assertContains`/delta-based `assertEquals`).

**Files:**
- Modify: `phpunit.xml` (riscrittura completa)
- Modify: `test/helpers/SnakeCase_PHPUnit_Framework_TestCase.php` (se emergono attriti)

**Interfaces:**
- Consumes: PHPUnit 12 (Task 2), runtime pulito (Task 3).
- Produces: `phpunit.xml` schema 12 con sezione `<source>` pronta per la coverage (Task 7); gate stretto `composer run test` di nuovo verde.

- [ ] **Step 1: Riscrivere phpunit.xml allo schema PHPUnit 12**

Sostituisci l'intero contenuto di `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="test/helpers/config.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="PHP ActiveRecord Test Suite">
            <directory>./test/</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">lib</directory>
        </include>
    </source>
</phpunit>
```

Note: rimossi gli attributi legacy `backupGlobals`, `backupStaticAttributes`, `convert*ToExceptions`, `processIsolation`, `stopOnFailure` (non più validi/necessari in PU12). Aggiungi `.phpunit.cache` a `.gitignore`.

- [ ] **Step 2: Aggiungere la cache dir a .gitignore**

Aggiungi la riga `.phpunit.cache` a `.gitignore` (crea il file se assente).

- [ ] **Step 3: Eseguire la suite con il gate stretto**

```bash
docker compose exec tests composer run test
```
Expected: verde su PHP 8.3. Se il wrapper `SnakeCase_PHPUnit_Framework_TestCase` genera errori (es. `__call` che non trova un metodo assert), correggi puntualmente in `test/helpers/SnakeCase_PHPUnit_Framework_TestCase.php` mantenendo lo stile snake_case; le assert PHPUnit restano metodi d'istanza in PU12.

- [ ] **Step 4: Verificare che nessun test skippi**

Controlla l'output del testdox: nessun test marcato "skipped" (memcached è presente nell'immagine, `TRAVIS` non è settato). `--fail-on-skipped` è già dentro `composer run test`.

- [ ] **Step 5: Commit**

```bash
git add phpunit.xml .gitignore test/helpers/SnakeCase_PHPUnit_Framework_TestCase.php
git commit -m "Migrates the PHPUnit configuration and test wrapper to PHPUnit 12"
```

---

## Task 5: Supporto multi-DB (MariaDB nella batteria adapter + verifica Postgres 18 / MySQL 9.7)

Estende la batteria `AdapterTest` condivisa a MariaDB e verifica che MySQL 9.7 e Postgres 18 passino l'intera batteria. Ogni divergenza parte da un test di integrazione rosso.

**Files:**
- Create: `test/MariadbAdapterTest.php`
- Modify (solo se emergono divergenze): `lib/adapters/MysqlAdapter.php`, `lib/adapters/PgsqlAdapter.php`, `test/sql/mysql.sql`, `test/sql/pgsql.sql`, `test/sql/pgsql-after-fixtures.sql`

**Interfaces:**
- Consumes: connessione `mariadb` (Task 1), gate stretto (Task 4).
- Produces: `MariadbAdapterTest` che esegue la batteria `MysqlAdapter` contro MariaDB 11.4.

- [ ] **Step 1: Creare MariadbAdapterTest (riusa la batteria MySQL)**

MariaDB parla il protocollo MySQL e usa lo stesso `MysqlAdapter`, quindi eredita da `MysqlAdapterTest` puntando alla connessione `mariadb`. `DatabaseTest::set_up()` imposta `$this->connection_name` dal parametro, e i test della batteria (`test_set_charset`, ecc.) usano `$this->connection_name` — quindi basta cambiare la connessione in `set_up`, senza riscrivere alcun test. Create `test/MariadbAdapterTest.php`:

```php
<?php

require_once __DIR__ . '/MysqlAdapterTest.php';

class MariadbAdapterTest extends MysqlAdapterTest
{
	public function set_up($connection_name=null)
	{
		AdapterTest::set_up('mariadb');
	}
}
```

(L'intera batteria `MysqlAdapterTest`/`AdapterTest` gira invariata contro MariaDB, perché tutti i test derivano la connessione da `$this->connection_name = 'mariadb'`. Nessun override di test → nessuna duplicazione.)

- [ ] **Step 2: Eseguire la batteria MariaDB**

```bash
docker compose exec tests vendor/bin/phpunit test/MariadbAdapterTest.php
```
Expected: verde. Se un test fallisce per una divergenza MariaDB (es. `raw_type` di `enum`, introspezione colonne), isola il caso: aggiungi/adegua il test, poi correggi `MysqlAdapter` privilegiando MySQL (se necessario ramifica sul comportamento MariaDB), poi ri-esegui.

- [ ] **Step 3: Eseguire l'intera batteria adapter contro tutti i backend**

```bash
docker compose exec tests vendor/bin/phpunit --filter Adapter
```
Expected: `MysqlAdapterTest` (MySQL 9.7), `MariadbAdapterTest` (MariaDB 11.4), `PgsqlAdapterTest` (Postgres 18), `SqliteAdapterTest` tutti verdi. Per ogni divergenza Postgres 18 (sequence/serial, tipi introspezionati) applica lo stesso ciclo TDD sul relativo adapter/SQL.

- [ ] **Step 4: Eseguire la suite completa (regressione cross-DB)**

```bash
docker compose exec tests composer run test
```
Expected: verde.

- [ ] **Step 5: Commit**

```bash
git add test/MariadbAdapterTest.php lib/adapters/ test/sql/
git commit -m "Runs the adapter battery against MariaDB 11.4 and verifies MySQL 9.7 and Postgres 18"
```

---

## Task 6: Pipeline GitHub Actions

Sostituisce CircleCI con GitHub Actions. Matrice per versione PHP (8.3/8.4/8.5); ogni job avvia **tutti** i backend come `services`, così ogni combinazione PHP×DB è coperta (la suite richiede tutti i DB accesi insieme — vedi nota).

> **Nota sulla matrice:** la decisione di brainstorming era "matrice completa PHP×DB". La suite però istanzia connessioni fisse per `mysql`/`mariadb`/`pgsql`/`sqlite` in un'unica esecuzione, quindi non è divisibile in un DB-per-job senza smontarla. La resa fedele è: **3 job (uno per PHP), ognuno con tutti e 4 i backend** → ogni PHP testato contro ogni DB. Copertura equivalente, 3 job invece di 12.

**Files:**
- Create: `.github/workflows/ci.yml`
- Delete: `.circleci/config.yml` (e la directory `.circleci/`)

**Interfaces:**
- Consumes: `composer run test` verde (Task 4/5), PHPStan (arriva in Task 7 — qui lo step PHPStan è già previsto e diventa verde dopo Task 7; vedi Step 1 nota).
- Produces: workflow CI `ci.yml`.

- [ ] **Step 1: Creare il workflow CI**

Create `.github/workflows/ci.yml`. Usa i servizi su `127.0.0.1` con porte mappate; le env `PHPAR_*` puntano a localhost.

```yaml
name: CI

on:
  push:
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php-version: ['8.3', '8.4', '8.5']

    services:
      mysql:
        image: mysql:9.7
        env:
          MYSQL_USER: phpar
          MYSQL_PASSWORD: secret
          MYSQL_DATABASE: phpar_test
          MYSQL_ROOT_PASSWORD: secret
        ports: ['3306:3306']
        options: >-
          --health-cmd="mysqladmin ping -psecret"
          --health-interval=5s --health-timeout=5s --health-retries=10
      mariadb:
        image: mariadb:11.4
        env:
          MARIADB_USER: phpar
          MARIADB_PASSWORD: secret
          MARIADB_DATABASE: phpar_test
          MARIADB_ROOT_PASSWORD: secret
        ports: ['3307:3306']
        options: >-
          --health-cmd="healthcheck.sh --connect --innodb_initialized"
          --health-interval=5s --health-timeout=5s --health-retries=10
      postgres:
        image: postgres:18
        env:
          POSTGRES_DB: phpar_test
          POSTGRES_USER: phpar
          POSTGRES_PASSWORD: secret
        ports: ['5432:5432']
        options: >-
          --health-cmd="pg_isready -U phpar"
          --health-interval=5s --health-timeout=5s --health-retries=10
      memcached:
        image: memcached:1.6
        ports: ['11211:11211']

    env:
      PHPAR_MYSQL: mysql://phpar:secret@127.0.0.1:3306/phpar_test
      PHPAR_MARIADB: mysql://phpar:secret@127.0.0.1:3307/phpar_test
      PHPAR_PGSQL: pgsql://phpar:secret@127.0.0.1:5432/phpar_test
      PHPAR_SQLITE: sqlite://test.db
      PHPAR_MEMCACHED: 127.0.0.1

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: pdo, pdo_mysql, pdo_pgsql, pgsql, memcached
          coverage: pcov

      - name: Install dependencies
        run: composer update --no-interaction --prefer-dist

      - name: Static analysis
        run: composer run analyse

      - name: Tests
        run: composer run test
```

> Nota: lo step "Static analysis" richiede `phpstan.neon` + baseline creati in Task 7. Se Task 6 viene mergiato prima di Task 7, rimuovi temporaneamente lo step "Static analysis" e riaggiungilo con Task 7. Ordine consigliato: Task 7 prima del merge di Task 6, oppure includi entrambi nello stesso PR.

- [ ] **Step 2: Rimuovere CircleCI**

```bash
git rm -r .circleci
```

- [ ] **Step 3: Validare il workflow localmente (sintassi)**

Verifica che il YAML sia ben formato:
```bash
docker compose exec tests php -r "var_dump(yaml_parse_file('.github/workflows/ci.yml') !== false);" 2>/dev/null || python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml')); print('ok')"
```
Expected: `ok` / `true`.

- [ ] **Step 4: Commit e push per far girare la CI**

```bash
git add .github/workflows/ci.yml
git rm -r .circleci
git commit -m "Replaces CircleCI with a GitHub Actions matrix across PHP 8.3-8.5 and all databases"
git push -u origin modernize-php85-ci-db
```
Expected: su GitHub, i 3 job (8.3/8.4/8.5) diventano verdi.

---

## Task 7: Extra qualità (PHPStan livello 5 + baseline, coverage, Dependabot)

Aggiunge analisi statica, wiring della coverage e aggiornamenti automatici. PHPStan gira a livello 5 con baseline che congela il debito esistente (correzione in piano futuro, fuori scope).

**Files:**
- Create: `phpstan.neon`
- Create: `phpstan-baseline.neon`
- Create: `.github/dependabot.yml`
- Modify: `Dockerfile` (aggiunta pcov)
- Modify: `.github/workflows/ci.yml` (step coverage — se non già presente da Task 6)

**Interfaces:**
- Consumes: `phpstan/phpstan` installato (Task 2), workflow CI (Task 6).
- Produces: `composer run analyse` verde; report coverage; `dependabot.yml`.

- [ ] **Step 1: Creare la config PHPStan a livello 5**

Create `phpstan.neon`. La libreria non ha autoloader PSR-4: PHPStan carica il manifest `ActiveRecord.php` come bootstrap.

```neon
parameters:
    level: 5
    paths:
        - lib
    bootstrapFiles:
        - ActiveRecord.php
    includes:
        - phpstan-baseline.neon
```

- [ ] **Step 2: Generare il baseline**

```bash
docker compose exec tests vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon --configuration phpstan.neon
```
Expected: crea `phpstan-baseline.neon` con gli errori esistenti congelati.

- [ ] **Step 3: Verificare che l'analisi sia verde contro il baseline**

```bash
docker compose exec tests composer run analyse
```
Expected: `[OK] No errors` (gli errori noti sono nel baseline).

- [ ] **Step 4: Aggiungere pcov all'immagine per la coverage locale**

Nel `Dockerfile`, dopo l'abilitazione di memcached, aggiungi:
```dockerfile
# Install pcov for code coverage
RUN pecl install pcov && docker-php-ext-enable pcov
```
Poi ricostruisci:
```bash
docker compose build tests && docker compose up -d
```

- [ ] **Step 5: Verificare la generazione della coverage (senza gate)**

```bash
docker compose exec tests vendor/bin/phpunit --coverage-text
```
Expected: report di coverage testuale in output (nessuna soglia imposta — il gate 90% è fuori scope).

- [ ] **Step 6: Aggiungere lo step coverage in CI**

Se non presente da Task 6, aggiungi in fondo agli `steps` di `.github/workflows/ci.yml` (dopo "Tests") uno step che pubblica la coverage come artifact; in alternativa cambia lo step "Tests" in:
```yaml
      - name: Tests
        run: composer run test -- --coverage-clover coverage.xml
      - name: Upload coverage
        uses: actions/upload-artifact@v4
        with:
          name: coverage-php${{ matrix.php-version }}
          path: coverage.xml
```

- [ ] **Step 7: Creare dependabot.yml**

Create `.github/dependabot.yml`:
```yaml
version: 2
updates:
  - package-ecosystem: "composer"
    directory: "/"
    schedule:
      interval: "weekly"
  - package-ecosystem: "github-actions"
    directory: "/"
    schedule:
      interval: "weekly"
```

- [ ] **Step 8: Commit**

```bash
git add phpstan.neon phpstan-baseline.neon .github/dependabot.yml .github/workflows/ci.yml Dockerfile
git commit -m "Adds PHPStan level 5 with baseline, coverage wiring, and Dependabot"
```

---

## Task 8: Documentazione e version bump 2.0.0

Aggiorna la documentazione operativa e la versione.

**Files:**
- Modify: `CLAUDE.md`
- Modify: `RELEASES.md`
- Modify: `ActiveRecord.php:5`

**Interfaces:**
- Consumes: tutti i task precedenti completati.
- Produces: nessuna interfaccia di codice.

- [ ] **Step 1: Bump versione in ActiveRecord.php**

In `ActiveRecord.php:5` cambia:
```php
define('PHP_ACTIVERECORD_VERSION_ID','1.8.0');
```
in:
```php
define('PHP_ACTIVERECORD_VERSION_ID','2.0.0');
```

- [ ] **Step 2: Aggiungere la nota di release**

In cima a `RELEASES.md`, sotto `# Release Notes`, aggiungi:
```markdown
## v2.0.0 (TBD)
* **BREAKING:** droppa il supporto a PHP < 8.3; la libreria ora richiede PHP ^8.3 e gira su 8.5
* **BREAKING:** aggiorna psr/log a ^3.0
* Migra la suite a PHPUnit 12 e la CI a GitHub Actions
* Aggiunge il supporto a MariaDB 11.4, MySQL 9.7 e PostgreSQL 18
* Aggiunge PHPStan (livello 5) e la generazione della coverage
```
(Sostituisci `TBD` con la data di rilascio al momento del merge.)

- [ ] **Step 3: Aggiornare CLAUDE.md**

Aggiorna in `CLAUDE.md` i riferimenti obsoleti:
- comandi da `docker-compose` a `docker compose` e file `compose.yaml`;
- goal "PHP 8.5" → raggiunto; floor `^8.3`;
- matrice CI: da CircleCI 7.4–8.2 a GitHub Actions 8.3/8.4/8.5;
- PHPUnit 9 → 12;
- DB: MySQL 9.7 / MariaDB 11.4 / Postgres 18; aggiunta connessione `mariadb` (`PHPAR_MARIADB`);
- analisi statica: PHPStan livello 5 (rimosso `check-compatibility`/PHPCompatibility).

- [ ] **Step 4: Verifica finale completa**

```bash
docker compose exec tests composer run test
docker compose exec tests composer run analyse
```
Expected: entrambi verdi.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md RELEASES.md ActiveRecord.php
git commit -m "Documents the 2.0.0 modernization and bumps the version"
```

---

## Self-Review

**Spec coverage:**
- PHP 8.5 / floor 8.3 → Task 2 (composer), Task 3 (runtime fixes). ✓
- CI GitHub Actions → Task 6. ✓
- PHPUnit 12 → Task 2 (dep), Task 4 (migrazione config/wrapper). ✓
- Dipendenze (monolog 3 / psr-log 3) → Task 2. ✓
- MariaDB 11.4 + MySQL 9.7 → Task 1 (infra + connessione + integrazione), Task 5 (batteria adapter). ✓
- PostgreSQL 18 → Task 1 (immagine), Task 5 (verifica batteria). ✓
- Coverage wiring senza gate → Task 7. ✓
- PHPStan livello 5 + baseline → Task 7. ✓
- Dependabot → Task 7. ✓
- Disciplina TDD (unit/funzionale/integrazione) → Task 1 (integrazione), Task 3 (unit/funzionale red-green), Task 5 (integrazione red-green). ✓
- Rimozione PHPCompatibility → Task 2. ✓
- Version bump 2.0.0 + docs → Task 8. ✓

**Placeholder scan:** nessun "TODO/TBD" nei passi operativi (l'unico `TBD` è la data di release in `RELEASES.md`, volutamente da compilare al merge). Ogni step di codice contiene il contenuto reale.

**Type/consistency:** `set_relationship_from_eager_load(?Model $model, $name)` usato coerentemente (Task 3). Nomi connessione `mariadb`/`PHPAR_MARIADB` coerenti in Task 1, 5, 6. Immagini `mysql:9.7`/`mariadb:11.4`/`postgres:18` coerenti tra compose (Task 1) e CI (Task 6).

**Nota di sequenza:** Task 6 e Task 7 vanno mergiati insieme (o Task 7 prima), perché lo step "Static analysis" della CI dipende da `phpstan.neon` + baseline. Documentato in Task 6 Step 1.
