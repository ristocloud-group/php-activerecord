# Modernizzazione php-activerecord — PHP 8.5, CI, PHPUnit, database

Data: 2026-07-29
Versione target: **2.0.0** (breaking: drop PHP < 8.3)

## Obiettivo

Portare il fork su una base moderna e supportata:

- Compatibilità runtime **PHP 8.5** (floor a **8.3**).
- Pipeline CI su **GitHub Actions** (abbandono CircleCI).
- **PHPUnit 12** (da 9) — mantenendo la DSL di test snake_case.
- Aggiornamento dipendenze (monolog 3, psr/log 3).
- Supporto **MariaDB 11.4** e **MySQL 9.7** (stesso `MysqlAdapter`).
- Supporto **PostgreSQL 18**.

Extra inclusi in questa spec: wiring coverage in CI (senza gate), PHPStan livello 5 con baseline, Dependabot.

## Vincoli e decisioni

- **Floor PHP 8.3.** Determina: PHPUnit 12 (13 richiede 8.4+), monolog 3, matrice CI `8.3 / 8.4 / 8.5`.
- **Breaking change** per i consumer (drop 7.4/8.0/8.1/8.2) → semver major → **2.0.0**.
- **DSL snake_case preservata.** Il wrapper `SnakeCase_PHPUnit_Framework_TestCase` e i 559 test restano nello stile attuale (convenzione di progetto). Le assert di PHPUnit sono ancora metodi d'istanza, quindi il wrapper `__call` sopravvive con adattamenti minimi.
- **MySQL first.** Se un fix diverge tra MySQL e MariaDB, MySQL vince; MariaDB con eventuale ramo dedicato.
- **PHPStan sostituisce PHPCompatibility.** PHPCompatibility 9.3 non copre in modo affidabile 8.4/8.5; PHPStan diventa l'analisi statica primaria. Si rimuove lo script `check-compatibility` e gli hook `post-install-cmd`/`post-update-cmd`.
- **Strategia di esecuzione: fasi bottom-up, un PR per fase**, per mantenere un segnale CI verde continuo durante una migrazione multi-asse.

## Stato di partenza (rilevato)

- `composer.json`: PHP `^7.4|^8.0`, PHPUnit `^9.0`, monolog `^2.0`, psr/log `^1.1`, phpcompatibility `^9.3`.
- CI: CircleCI, matrice `7.4/8.0/8.1/8.2`, `docker-compose` v1, immagine `cimg/base:2021.04`.
- `docker-compose.yml`: `version: '3'`, default PHP 7.4, `mysql:5.6` (`platform: linux/x86_64`), `postgres:9.6`, `memcached:1.4`.
- `phpunit.xml`: schema pre-10 (attributi `convert*ToExceptions`), nessuna config coverage.
- Superficie runtime 8.5 **piccola**: niente `each()`, niente accesso con graffe, nessun `ReturnTypeWillChange` mancante; 1 solo candidato a parametro nullable implicito; `ENGINE=InnoDB` (non il rimosso `TYPE=`); introspezione MySQL via `SHOW COLUMNS`/`SHOW TABLES` (compatibile MySQL 9.7 e MariaDB 11.4).

## Fasi (un PR ciascuna)

### Fase 1 — Fondamenta ambiente locale
- `docker-compose.yml` → `compose.yaml` v2 (rimozione chiave `version:`).
- Immagini: `mysql:9.7`, `mariadb:11.4`, `postgres:18`, `memcached` recente.
- `Dockerfile`: base `php:8.3-cli` (default `ARG PHP_VERSION=8.3`).
- Nuovo servizio MariaDB + variabile `PHPAR_MARIADB` accanto a `PHPAR_MYSQL` / `PHPAR_PGSQL`.
- Rimozione `platform: linux/x86_64` (immagini arm64 native → veloci su Apple Silicon).
- **Gate:** `docker compose up -d` sano; suite attuale ancora eseguibile.

### Fase 2 — Dipendenze
- `composer.json`: `php: ^8.3`, `phpunit/phpunit: ^12`, `monolog/monolog: ^3`, `psr/log: ^3`, `phpstan/phpstan` (require-dev).
- Rimozione `phpcompatibility/php-compatibility`, script `check-compatibility`, hook `post-install-cmd`/`post-update-cmd`.
- `spatie/phpunit-watcher`: versione compatibile PHPUnit 12, oppure rimozione se non pronta (tooling locale, non critico).
- **Gate:** `composer update` risolve; autoload ok.

### Fase 3 — Fix runtime PHP 8.5
- Fix mirati e behavior-preserving in `lib/`: parametro nullable implicito individuato + eventuali deprecation 8.4/8.5 emerse a runtime.
- `#[\AllowDynamicProperties]` solo dove effettivamente necessario (atteso: mai — il modello usa `__get`/`__set`).
- **Gate:** suite verde su PHP 8.5, zero deprecation.

### Fase 4 — Migrazione PHPUnit 12
- Riscrittura `phpunit.xml` allo schema moderno: rimozione attributi `convert*ToExceptions`, aggiunta `cacheDirectory`, sezione `<source>` per la coverage.
- Adattamento wrapper `SnakeCase_PHPUnit_Framework_TestCase`: rename degli alias rimossi se usati — `assertRegExp` → `assertMatchesRegularExpression`, `assertContains` su stringhe → `assertStringContainsString`, `assertEquals` con delta → `assertEqualsWithDelta`.
- I 559 test restano scritti in snake_case.
- **Gate:** `composer run test` verde, `--fail-on-skipped` incluso.

### Fase 5 — Supporto multi-DB
- Verifica `MysqlAdapter` contro MySQL 9.7 **e** MariaDB 11.4 (introspezione, quoting, `LIMIT`, tipi/colonne restituiti).
- Verifica `PgsqlAdapter` contro Postgres 18 (sequence/serial, `test/sql/pgsql-after-fixtures.sql`).
- Adeguamento `test/sql/*.sql` se emergono differenze di sintassi.
- `AdapterTest` esteso con connessione `mariadb` per farlo girare nella batteria condivisa.
- **Gate:** batteria adapter verde su tutti e quattro i backend.

### Fase 6 — GitHub Actions
- Nuovo `.github/workflows/ci.yml`: matrice completa PHP `{8.3, 8.4, 8.5}` × DB `{mysql 9.7, mariadb 11.4, postgres 18, sqlite}` (~12 job), via `services:` + `shivammathur/setup-php`.
- Step per job: install, PHPStan, test con coverage.
- Rimozione directory `.circleci/`.
- **Gate:** matrice verde su un PR di prova.

### Fase 7 — Extra qualità
- `phpstan.neon` a **livello 5** + `phpstan-baseline.neon` che cattura tutte le segnalazioni esistenti (CI verde da subito). **La correzione del baseline è fuori scope** → piano futuro dedicato.
- Report coverage in CI, **senza gate 90%** (la copertura al 90% resta lavoro separato di scrittura test).
- `.github/dependabot.yml`: aggiornamenti automatici per `composer` e `github-actions`.
- **Gate:** PHPStan livello 5 verde contro baseline; coverage caricata.

## Interfacce / API pubblica

Nessuna modifica alla API pubblica snake_case. I consumer del monolite vedono solo: requisito PHP ≥ 8.3 e psr/log 3.

## Rischi

- **MySQL 9 caching_sha2**: gestito dal `pdo_mysql` dell'immagine `php:8.3` (client recente). Se emergono problemi di auth in CI, documentare fallback.
- **spatie/phpunit-watcher** potenzialmente non pronto per PHPUnit 12 → rimozione (solo tooling locale).
- **Divergenze MariaDB vs MySQL**: isolate in Fase 5; MySQL vince, MariaDB con ramo dedicato se necessario.

## Testing

La suite esistente è il gate. Ogni fase chiude con `docker compose exec tests composer run test` verde. `--fail-on-skipped` resta attivo: i `markTestSkipped` per memcached/cache non devono scattare (servizi su nel compose).

## Documentazione

- `CLAUDE.md`: comandi `docker compose` v2, matrice CI, PHPUnit 12, DB nuovi, PHPStan al posto di check-compatibility.
- `RELEASES.md`: nota 2.0.0 + breaking changes (drop PHP < 8.3, psr/log 3).
- `ActiveRecord.php`: bump `PHP_ACTIVERECORD_VERSION_ID` a `2.0.0`.
