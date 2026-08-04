# PHPStan livello 8 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Portare l'analisi statica PHPStan della libreria da livello 5 a livello 8 con zero errori e senza nuove voci di baseline, salendo un livello alla volta.

**Architecture:** Tre "gradini" sequenziali, un PR per livello (6 → 7 → 8). Ogni PR bumpa `phpstan.neon` al proprio livello solo quando l'analisi a quel livello è a zero. Le annotazioni di tipo si aggiungono **PHPDoc-first** (zero BC runtime); i tipi nativi solo su membri privati/interni. I bug reali rivelati dai tipi escono in PR dedicati con test di regressione (TDD); i casi che toccano return type di API pubblica si fermano e richiedono conferma del maintainer.

**Tech Stack:** PHP ^8.3, PHPStan (level parametrizzato in `phpstan.neon`, baseline in `phpstan-baseline.neon`), PHPUnit (via `composer run test`), PHP-CS-Fixer (`composer run cs`), tutto dentro Docker (`docker compose exec -T tests …`).

## Global Constraints

- **PHP >= 8.3**, sintassi moderna: short array `[]`, tipi dichiarati, `??`/`?->`, `[$a,$b]=…`. Mai `array()`.
- **API pubblica snake_case** — mai rinominare a camelCase.
- **Backward-compatibility è un hard gate.** Non cambiare firma/return type/eccezioni/comportamento osservabile di metodi pubblici, config statiche (`$has_many`, `$validates_*`, callback…), chiavi di opzione o proprietà pubbliche. Nel dubbio è breaking → **stop e chiedi al maintainer**.
- **PHPDoc-first:** i `missingType.*` su membri **pubblici/protetti** si risolvono con `@param`/`@return`/`@var`/array-shape, **mai** con tipi nativi che cambiano la firma. Tipi nativi ammessi solo su membri `private` o funzioni/metodi interni non ereditati e non esposti.
- **Nessuna nuova voce di baseline.** La baseline attuale (`phpstan-baseline.neon`, 6 voci di livello 5) non cresce: obiettivo di ogni gradino è zero errori a quel livello senza aggiungere righe. **Eccezione approvata dal maintainer (2026-08-04):** quando tipizzare un parametro/proprietà riscrive il *testo* di una voce di baseline già presente (es. `is_array()`/`is_null()` "always evaluate to true", il cui messaggio ora include lo shape dell'array), è consentito **aggiornare la regex `message:` di quella voce** per tracciare il testo riscritto. Il numero di voci resta invariato, la soppressione è la stessa (stessa riga, stessa regola), cambia solo il testo. Non è consentito aggiungere voci nuove né sopprimere errori diversi.
- **Stile:** dopo ogni modifica far passare `composer run cs` (PER-CS 3.0 + PHP 8.3 migration).
- **Suite:** `composer run test` deve restare verde a ogni commit (è il regression guard; PHP 8.5 è coperto dalla CI, la suite locale gira su 8.3).
- **Priorità adapter:** MySQL vince i trade-off; non rompere Postgres/SQLite/MariaDB.

## Comandi di riferimento

```sh
# analisi di tutta la lib a un livello specifico
docker compose exec -T tests vendor/bin/phpstan analyse lib --level=6 --memory-limit=1G --no-progress

# analisi di UN SOLO file (usa comunque config, bootstrap e baseline del progetto)
docker compose exec -T tests vendor/bin/phpstan analyse lib/SQLBuilder.php --level=6 --memory-limit=1G --no-progress

# suite completa (gate CI) e stile
docker compose exec -T tests composer run test
docker compose exec -T tests composer run cs        # dry-run; cs-fix per applicare
```

---

## PR-A — Livello 6 (513 errori, tutti `missingType.*`)

Al livello 6 **tutte** le 513 segnalazioni sono annotazioni di tipo mancanti (`missingType.return/parameter/property/iterableValue/generics`). Nessun bug logico emerge a questo livello: è lavoro puramente meccanico, un file per task.

### Ricetta di annotazione (vale per ogni task A1–A13)

Ogni task di questo PR segue **esattamente** questi 5 step, sostituendo `<FILE>` col path del file e `<BRANCH>` con `phpstan/level-6`:

- [ ] **Step 1 — Elenca gli errori del file.**
  Run: `docker compose exec -T tests vendor/bin/phpstan analyse <FILE> --level=6 --memory-limit=1G --no-progress`
  Expected: la lista degli errori `missingType.*` per quel file (deve combaciare col conteggio indicato nel task).

- [ ] **Step 2 — Aggiungi le annotazioni (PHPDoc-first).**
  Per ciascun errore:
  - `missingType.iterableValue` (`array` senza value type) → aggiungi/estendi il docblock con `@var`/`@param`/`@return array<K, V>` (o `list<T>` se lista a chiavi intere contigue). Es. un array associativo di config → `array<string, mixed>`; una lista di colonne → `list<string>`.
  - `missingType.property` (proprietà senza tipo) → se **private**: tipo nativo (`private Connection $connection;`). Se **public/protected**: docblock `/** @var Tipo */` sopra la proprietà, senza toccare la firma.
  - `missingType.return` / `missingType.parameter` su metodi/funzioni → se il membro è **private/interno**: tipo nativo. Se **public/protected/ereditabile**: `@param Tipo $x` / `@return Tipo` nel docblock, senza tipo nativo.
  - `missingType.generics` (classe che estende/implementa un tipo generico senza parametri) → docblock `@extends Base<...>` / `@implements Iface<...>` sulla classe.
  - Se per tipizzare correttamente servirebbe cambiare la **firma nativa di un metodo pubblico** (o emergono return type incoerenti) → **NON farlo qui**: annotalo col tipo reale via PHPDoc e segnalo il caso; se è un bug o tocca la BC va in PR-B/PR-C o in un PR-bug (vedi sezioni successive).

- [ ] **Step 3 — Verifica il file a zero.**
  Run: `docker compose exec -T tests vendor/bin/phpstan analyse <FILE> --level=6 --memory-limit=1G --no-progress`
  Expected: `[OK] No errors`. Se compaiono errori **nuovi** in altri file (una `@var` troppo stretta può propagarsi), allarga il tipo (`mixed`/union) finché il progetto non regredisce.

- [ ] **Step 4 — Stile + suite (regression guard).**
  Run: `docker compose exec -T tests composer run cs` (se serve, `cs-fix`), poi `docker compose exec -T tests composer run test`
  Expected: stile verde e suite completa verde. Le modifiche sono di solo-tipo, quindi il comportamento non deve cambiare; una suite rossa qui = un tipo nativo introdotto per errore su un membro pubblico → riportalo a PHPDoc.

- [ ] **Step 5 — Commit (uno per file).**
  ```bash
  git add <FILE>
  git commit -m "types(phpstan-l6): annotate <basename di FILE>"
  ```

### Ordine dei task (dalle foglie al core)

I file "foglia" (valori/utility) si tipizzano prima, così i tipi che espongono sono disponibili quando si annotano i file core.

- [ ] **Task A0 — Branch.** `git checkout master && git checkout -b phpstan/level-6`. Verifica baseline attuale: `docker compose exec -T tests vendor/bin/phpstan analyse lib --level=5 --memory-limit=1G --no-progress` → `[OK]` (parità di partenza).

- [ ] **Task A1 — `lib/Utils.php`** (49 err: 25 parameter, 18 return, 4 property, 2 iterableValue). Funzioni globali del namespace (`classify()`, `array_flatten()`, `denamespace()`, …). Molte sono helper interni non ereditati → tipi nativi ammessi su parametri/return; usa array-shape per gli array. Segui la Ricetta di annotazione.

- [ ] **Task A2 — foglie piccole:** `lib/Inflector.php` (8), `lib/Column.php` (1), `lib/Reflections.php` (2, di cui 1 `generics` → `@extends`/`@implements` sull'iterazione delle reflection), `lib/Singleton.php` (1), `lib/Exceptions.php` (2), `lib/ConnectionManager.php` (1). Un commit per file. Segui la Ricetta.

- [ ] **Task A3 — `lib/Config.php`** (8) **e** `lib/DateTime.php` (8, 4 property + 2 return + 2 parameter). Un commit per file. Segui la Ricetta.

- [ ] **Task A4 — adapters:** `lib/adapters/MysqlAdapter.php` (7), `lib/adapters/SqliteAdapter.php` (7), `lib/adapters/PgsqlAdapter.php` (6). I metodi `columns()`/`tables()`/`quote_name()` ecc. sono ereditati/overridati → **PHPDoc** per return/param, non tipi nativi (LSP con la classe base astratta). Un commit per file. Segui la Ricetta.

- [ ] **Task A5 — cache + callback:** `lib/Cache.php` (9), `lib/cache/File.php` (10), `lib/cache/Memcache.php` (9), `lib/cache/Redis.php` (6), `lib/CallBack.php` (6, tutti iterableValue/parameter). Un commit per file. Segui la Ricetta.

- [ ] **Task A6 — `lib/Connection.php`** (17: 9 return, 6 iterableValue, 1 property, 1 parameter). NB: qui vivono bug reali (righe ~206, ~527) che però emergono ai livelli 7/8 — a livello 6 aggiungi solo le annotazioni mancanti col tipo **reale** (es. `@return DateTime|null` dove serve), senza correggere logica. Segui la Ricetta.

- [ ] **Task A7 — `lib/Expressions.php`** (28: 15 parameter, 10 return, 3 property). Segui la Ricetta.

- [ ] **Task A8 — `lib/SQLBuilder.php`** (66: 21 return, 19 property, 18 parameter, 8 iterableValue). Le proprietà (`$connection`, `$operation`, `$table`, `$select`, `$joins`, …) sono per lo più uso interno del builder. `$connection` → `Connection`; gli array (`$where`, `$data`, `$order`) → `@var array<string, mixed>` o `list<...>` secondo l'uso. Segui la Ricetta.

- [ ] **Task A9 — `lib/Relationship.php`** (65: 26 iterableValue, 20 return, 17 parameter, 2 property). Include l'interfaccia `InterfaceRelationship` e le classi `HasMany`/`HasOne`/`BelongsTo`/`HasAndBelongsToMany`. `$options`/`$attributes` sono array associativi → `array<string, mixed>`. Return di `build_association`/`create_association` → PHPDoc `@return Model|null` col tipo reale. Segui la Ricetta.

- [ ] **Task A10 — `lib/Table.php`** (63: 28 parameter, 22 return, 9 property, 4 iterableValue). Proprietà: `$cache`, `$class` (→ `\ReflectionClass`), `$pk` (`list<string>`), `$last_sql` (`string`), `$columns` (`array<string, Column>`), … via `@var`. Segui la Ricetta.

- [ ] **Task A11 — `lib/Model.php`** (51: 39 iterableValue, 9 return, 3 parameter). Quasi tutto sono le proprietà/config statiche pubbliche (`$attributes`, `$__dirty`, `$__relationships`, `$alias_attribute`, `$attr_accessible`, `$has_many`, …) → **`@var` array-shape**, mai tipo nativo (sono API pubblica e vengono ridefinite nelle sottoclassi). Es.: `$attributes` → `array<string, mixed>`; `$has_many` → `list<array<string, mixed>>`. Segui la Ricetta.

- [ ] **Task A12 — `lib/Validations.php`** (50: 18 return, 13 iterableValue, 11 property, 5 parameter, 3 generics). I 3 `generics` sono su `Errors`/iteratori → `@implements`/`@extends`. Le macro `$validates_*` sono config pubbliche → `@var`. Segui la Ricetta.

- [ ] **Task A13 — `lib/Serialization.php`** (33: 12 return, 9 property, 8 iterableValue, 4 parameter). Segui la Ricetta.

- [ ] **Task A14 — Bump del livello + verifica finale.**
  - [ ] Step 1: modifica `phpstan.neon`, `level: 5` → `level: 6`.
  - [ ] Step 2: `docker compose exec -T tests vendor/bin/phpstan analyse lib --level=6 --memory-limit=1G --no-progress` → `[OK] No errors` (senza nuove voci di baseline).
  - [ ] Step 3: `docker compose exec -T tests composer run analyse` → verde (usa il livello dal config).
  - [ ] Step 4: `docker compose exec -T tests composer run cs` e `composer run test` → verdi.
  - [ ] Step 5: commit `chore(phpstan): raise analysis level to 6` e apri il PR-A.

---

## PR-B — Livello 7 (+73 errori)

Branch `phpstan/level-7` da `master` (dopo il merge di PR-A). A livello 7 emergono narrowing di union type e rami morti. Alcuni sono raffinamenti di annotazione, altri possono essere bug logici → in tal caso diventano PR-bug (vedi sotto), non pulizie silenziose.

- [ ] **Task B1 — Censimento livello 7.**
  - [ ] Step 1: `docker compose exec -T tests vendor/bin/phpstan analyse lib --level=7 --memory-limit=1G --no-progress --error-format=json > /tmp/l7.json`
  - [ ] Step 2: raggruppa per identifier e file (es. `booleanNot.alwaysFalse`, `return.type`, narrowing). Per ciascuno classifica: **(a) annotazione da raffinare**, **(b) guardia mancante**, **(c) bug logico**.
  - [ ] Step 3: annota nel PR la classificazione. I casi (c) → apri PR-bug dedicato (sezione "PR-bug") e mettili fuori da questo PR.

- [ ] **Task B2 — Risoluzione livello 7.**
  - [ ] Step 1: per i casi (a) restringi le union nei docblock al tipo effettivo.
  - [ ] Step 2: per i casi (b) aggiungi guardie esplicite (`instanceof`, `is_string(...)`, early-return) o `assert()` dove l'invariante è garantita a runtime ma non deducibile staticamente. Nessun cambio di comportamento osservabile.
  - [ ] Step 3: `docker compose exec -T tests vendor/bin/phpstan analyse lib --level=7 …` → `[OK]`; `composer run cs`; `composer run test` verdi. Commit per gruppo logico di file.
  - [ ] Step 4: bump `phpstan.neon` a `level: 7`, verifica `composer run analyse`/`test`/`cs`, commit `chore(phpstan): raise analysis level to 7`, apri PR-B.

---

## PR-C — Livello 8 (+47 errori)

Branch `phpstan/level-8` da `master` (dopo il merge di PR-B). A livello 8 emergono accessi a metodi/proprietà su tipi nullable (`X|null`, `DateTime|false`). Molti si risolvono con null-guard/nullsafe; alcuni sono bug reali (vedi PR-bug).

- [ ] **Task C1 — Censimento livello 8.**
  - [ ] Step 1: `docker compose exec -T tests vendor/bin/phpstan analyse lib --level=8 --memory-limit=1G --no-progress --error-format=json > /tmp/l8.json`
  - [ ] Step 2: classifica ciascun `method.nonObject`/`property.nonObject`/`offsetAccess.*`: (a) nullable che a runtime non è mai null → guardia/nullsafe difensivi; (b) bug reale → PR-bug.

- [ ] **Task C2 — Risoluzione livello 8.**
  - [ ] Step 1: aggiungi null-guard (`if (null === $x) { … }`), nullsafe (`$x?->foo()`) o narrowing dove il valore è garantito non-null. Nessun cambio di comportamento osservabile per gli input validi.
  - [ ] Step 2: `phpstan --level=8` → `[OK]`; `cs`; `test` verdi. Commit per gruppo.
  - [ ] Step 3: bump `phpstan.neon` a `level: 8`, verifica `composer run analyse`/`test`/`cs`, commit `chore(phpstan): raise analysis level to 8`, apri PR-C. **Definizione di fatto raggiunta.**

---

## PR-bug — bug reali (TDD, uno per PR)

Ogni bug esce dal flusso "tipi" in un PR dedicato con test di regressione **che fallisce prima del fix e passa dopo**. Branch tipo `fix/<slug>`. I casi marcati **BC** richiedono conferma esplicita del maintainer prima di procedere (STOP).

### Bug 1 — `Connection::string_to_datetime()` accede a `format()` su `DateTime|false`

`lib/Connection.php:~520` — `date_create($string)` ritorna `DateTime|false`; `$date->format(...)` a riga ~527 può essere invocato su `false`. Fix difensivo, nessun cambio di comportamento per input validi.

- [ ] **Step 1 — Test che fallisce.** In un test che estende `DatabaseTest` (es. aggiungi a `test/ConnectionTest.php`):
```php
public function test_string_to_datetime_returns_null_on_invalid_input()
{
    $conn = ActiveRecord\Connection::instance();
    $this->assert_null($conn->string_to_datetime('not a date at all'));
}
```
- [ ] **Step 2 — Verifica il fallimento.** Run: `docker compose exec -T tests vendor/bin/phpunit --filter test_string_to_datetime_returns_null_on_invalid_input`. Expected: FAIL (TypeError su `false->format()` o valore non-null), a conferma del difetto.
- [ ] **Step 3 — Fix minimale.** In `string_to_datetime()`, dopo `$date = date_create($string);` aggiungi:
```php
if (false === $date) {
    return null;
}
```
- [ ] **Step 4 — Verifica pass + suite.** Run il filtro sopra (PASS) e `composer run test` (verde).
- [ ] **Step 5 — Commit.** `git commit -m "fix(connection): string_to_datetime returns null on unparseable input"`.

### Bug 2 — `Inflector::underscorify()` può ritornare `null`

`lib/Inflector.php:~104` — `preg_replace(...)` ha tipo di ritorno `string|null`; la firma dichiara `@return string`.

- [ ] **Step 1 — Test che fallisce/di regressione.** In `test/InflectorTest.php`:
```php
public function test_underscorify_returns_string()
{
    $s = ActiveRecord\Inflector::instance()->underscorify('FooBar baz');
    $this->assert_same('Foo_Bar_baz', $s);
}
```
- [ ] **Step 2 — Verifica.** Run: `docker compose exec -T tests vendor/bin/phpunit test/InflectorTest.php --filter test_underscorify_returns_string`. Expected: PASS a livello runtime (il valore è già corretto) ma PHPStan L7/L8 segnala `return.type` — il test blinda il comportamento prima del fix di tipo.
- [ ] **Step 3 — Fix minimale.** `return preg_replace([...], [...], trim($s)) ?? '';` (il `?? ''` copre il ramo `null` teorico senza cambiare l'output reale).
- [ ] **Step 4 — Verifica.** Filtro sopra PASS; `composer run test` verde; l'errore PHPStan corrispondente sparisce.
- [ ] **Step 5 — Commit.** `git commit -m "fix(inflector): underscorify never returns null"`.

### Bug 3 — `Connection` URL parsing e `substr` su `string|null` (riga ~206)

`lib/Connection.php:~206` — `substr($string, ...)` con `$string` potenzialmente `null` (da `parse_url`/`$url->query`). Al censimento L7/L8 conferma il punto esatto (le righe si spostano dopo PR-A) e aggiungi la guardia/`?? ''` mantenendo il parsing invariato per URL validi. Test: aggiungi un caso a `test/ConnectionTest.php` che parsa un URL senza il componente opzionale e verifica il risultato atteso. Stessa struttura TDD a 5 step dei bug precedenti.

### Bug 4 (**BC — STOP e conferma**) — return type di `Model::to_json/to_xml/to_csv/to_array`

`lib/Model.php:~1767+` — questi metodi dichiarano di ritornare `string` (o `array`) ma possono ritornare `array|string`. Correggere la firma o restringere il tipo **cambia la BC osservabile**. **Non procedere senza approvazione esplicita del maintainer.** Se approvato, il fix (allineare `@return`/comportamento) diventa un PR-bug TDD con un test che documenta il tipo concordato; altrimenti si annota il tipo reale via PHPDoc (`@return array|string`) e si lascia il comportamento invariato — decisione da confermare.

---

## Self-review (già eseguito su questo piano)

- **Copertura spec:** salita incrementale 6→7→8 (PR-A/B/C) ✓; PHPDoc-first con regola nativo-solo-se-privato (Global Constraints + Step 2 della ricetta) ✓; un PR per livello ✓; bug reali in PR separati con TDD e gate BC (sezione PR-bug, tutti i 5 candidati dello spec: Connection:527, underscorify, Connection:206, Reflections:36 gestito nel censimento tipi/generics, to_json&c. come Bug 4 BC) ✓; verifica per PR (analyse=0 + test + cs) ✓; niente nuove baseline ✓.
- **Nota su Reflections:36 (`class-string`):** è un `argument.type` che emerge a livello 8; se sopravvive al censimento C1 come bug reale diventa un PR-bug con la stessa struttura, altrimenti si risolve con `@param class-string $x` nella tipizzazione.
- **Placeholder:** nessun TODO/TBD; i censimenti L7/L8 sono step d'azione reali (rigenerare la lista è necessario perché le righe si spostano dopo PR-A), non segnaposto.
- **Coerenza tipi/nomi:** livelli e comandi coerenti; branch `phpstan/level-6|7|8`; ordine foglie→core rispettato.

---

## Esito PR-A (livello 6) — completato 2026-08-04

PR-A eseguito task-per-task (A0–A14) su branch `phpstan/level-6`. **L'intera `lib/` è pulita al livello 6 su PHP 8.3 e 8.5**, baseline invariata a 6 voci (i due messaggi `is_array` di Config e Model riscritti per il testo, per la policy approvata), **zero `@phpstan-ignore`**, `composer run analyse`/`cs`/`test` verdi (883 test). Review finale di tutto il branch: **Ship**, nessun difetto Critical/Important.

Due fix forzati dalla tipizzazione onesta, entrambi con test/verifica:
- `Relationship::inject_foreign_key_for_new_association` → `set_keys(get_class($model))` (bug reale: prima passava il `Model`, TypeError quando la FK non era ancora inferita) + 2 test di regressione in `test/RelationshipTest.php`.
- `Validations::validate` → invoca l'hook opzionale via `$model_reflection->getMethod('validate')->invoke(...)` (comportamento identico, nessuna soppressione).
- `Table::$class` corretto a `\ReflectionClass<object>` (allineato a `Reflections::get()`; risolve un errore di varianza solo-8.5).

### Item scoperti durante PR-A → per PR-B (liv.7), PR-C (liv.8) e i bug-PR

Latenti/bug (candidati bug-PR con test):
- **Inflector** `underscorify`/`keyify`/`tableize`/`variablize`: `@return string` ma `preg_replace` può dare `null` (è il **Bug 2** del piano).
- **Utils** `is_odd()` ritorna `int` non `bool`; `pluralize`/`singularize`/`pluralize_if`/`squeeze` possono dare `null` senza guardia ai call-site.
- **Connection** `string_to_datetime` (`DateTime|false`, **Bug 1**) e `substr` su `string|null` (~206, **Bug 3**) — emergono a L7/L8.
- **Model** `to_json/to_xml/to_csv/to_array` return `array|string` vs firma — **Bug 4, BC-gated, STOP e chiedi**.
- `Table::upsert(array|string $unique_by)` → `SQLBuilder::upsert(array)`: **verificato NON un bug vivo** — `Table::upsert` normalizza `is_array($unique_by) ? … : [$unique_by]` prima dell'uso. (Solo un `argument.type` che potrebbe apparire a L7 e va risolto con annotazione, non un fix.)

Minor/accuratezza (da rifinire nei pass L7/L8, non bloccanti):
- `DatabaseException` `@param` union omette `\Throwable`/`\Stringable` (passati dai call-site di Connection); allargare.
- `Reflections::get` `@param string` troppo stretto (accetta anche `object`).
- `XmlSerializer::xml_encode` `@return string` vs `preg_replace` null (L7/8).
- PgsqlAdapter `create_column` allargato a `array<string,mixed>` (ambiguità protocollo testo pgsql).
- `Memcache::write` senza default `$expire` (File/Redis usano `0`) — incoerenza di contratto pre-esistente.
- `upsert_conflict_clause` (da PR#9) usa tipi nativi su metodo pubblico (pre-esistente).
- Nit cosmetici: `SQLBuilder:367` `$new` dopo `@return`; `get_meta_data` senza `: void` nativo per coerenza; `wrap_strings_in_arrays` `@return` largo.
