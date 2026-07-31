# PHPStan cleanup + PER-CS 3.0 coding style — Design

Date: 2026-07-31
Status: Approved (design)

## Obiettivo

Due lavori distinti, su **due PR separate**:

1. **Ridurre gli errori PHPStan** (livello 5) risolvendo tutti i casi sicuri e sottoponendo al maintainer, uno per uno, ogni fix potenzialmente breaking o sospetto. I casi genuinamente rischiosi restano in una baseline ridotta.
2. **Adottare il coding style PER-CS 3.0** su tutto il codice PHP del repo, applicato ed enforced automaticamente.

Le due PR sono indipendenti. Ordine di merge consigliato: **PhpStan prima, reformat dopo**, per non annegare i fix logici in un diff meccanico da ~100 file. Nota: la baseline PHPStan usa `message + identifier + count + path` (nessun numero di riga), quindi il reformat non la invalida — l'ordine è una scelta di sola reviewabilità.

## Stato di partenza

- PHPStan livello 5, `paths: lib`, bootstrap `ActiveRecord.php`. **138 errori** reali, oggi tutti silenziati da `phpstan-baseline.neon` (~90 voci). CI gira `composer run analyse` nel job PHP 8.3.
  - Distribuzione: `Model.php` 39 · `Relationship.php` 22 · `Connection.php` 17 · `Table.php` 14 · `Validations.php` 11 · adapters 14 · resto 21.
- Coding style: `squizlabs/php_codesniffer` è dev dependency **ma non configurato** (nessun `phpcs.xml`, nessuno script, nessuno step CI) → di fatto lo stile non è enforced.
- PHP_CodeSniffer 3.13.5 **non ha uno standard PER** (solo fino a PSR-12). PHP-CS-Fixer, unico tool con `@PER-CS3.0` nativo + auto-fix, non è installato.
- Il codebase legacy usa **tab**; PER-CS 3.0 impone **4 spazi**.

---

## Workstream 1 — Riduzione PHPStan (PR #1)

### Strategia

- Livello 5 invariato (no scope creep sul livello).
- Si lavora per file, seguendo i gruppi A–F sotto, usando la skill `php-pro`.
- **Applico direttamente** i fix sicuri/behavior-neutral (gruppo A e parte di B).
- **Sottopongo al maintainer prima di applicare** (con failure-scenario esplicito) ogni fix nei gruppi C, D, E, F. Questo attua il gate di backward-compatibility del CLAUDE.md: qualsiasi cambio di firma pubblica, tipo di ritorno o comportamento osservabile va confermato.
- Ciò che il maintainer declina, o che risulta troppo rischioso, **resta in `phpstan-baseline.neon`**, che viene rigenerata e risulta più piccola dell'attuale.

### Gruppi di errori

**A. Tipi "rotti" nei docblock/signature — sicuri, non breaking → applico diretto**
- `ActiveRecord\str`, `ActiveRecord\A`, `ActiveRecord\unknown_type`: nomi di tipo inesistenti (refusi per `string`/`array`) in `Exceptions.php`, `SQLBuilder.php`, `Utils.php`, `Model.php`.
- `ActiveRecord\PDOStatement` "class not found" (Connection + adapters): manca il backslash → `\PDOStatement`.
- PHPDoc malformati (`@param`/`@throws`/`@return` con sintassi errata; doppio namespace `ActiveRecord\ActiveRecord\…`): pura correzione documentazione.

**B. Return type / `void` che ritorna un valore — quasi tutti sicuri**
- Metodi `void` che fanno `return $this`/`return $x` (`__set`, `__clone`, `set_relationship_from_eager_load`, `drop_connection`).
- `count()` → `string` ma dichiara `int`; `to_array()`/`to_s()` mismatch; `get_real_attribute_name()`/`get_connection()`/`next_sequence_value()` ritornano `null` su tipo non-nullable.
- I fix che cambiano un tipo di ritorno **osservabile** (es. cast in `count()`) vengono sottoposti al maintainer.

**C. Tipo `ActiveRecord\Relationship` inesistente — SOTTOPORRE**
- Il codice usa un tipo `Relationship` che non esiste (classi reali: `AbstractRelationship`/`HasMany`/`HasOne`/`BelongsTo`/`HasAndBelongsToMany`), pervasivo in `Table.php`/`Model.php`/`Relationship.php`.
- Il fix tocca le firme di `Table::get_relationship()` (return type) e `Table::add_relationship()` (param type) → API semi-pubblica.

**D. Proprietà con tipo troppo stretto — bug di tipo reale, SOTTOPORRE**
- `AbstractRelationship::$foreign_key` è `string` ma riceve `array` (has_many through); `Column::$length`/`$sequence`, `Table::$conn`, `Model::$__dirty` non accettano `null`/il tipo assegnato.
- Allargare il tipo è corretto ma cambia una proprietà pubblica.

**E. Codice morto / condizioni sempre vere-false — le più SOSPETTE, una per una**
- `if.alwaysTrue`, `deadCode.unreachable`, `booleanOr/booleanNot always…`, `is_array/is_null/is_numeric` sempre veri/falsi, `empty()`/`isset()` su variabili sempre esistenti, espressioni senza effetto (`$this->{$assoc}` isolato, `array($enum)` isolato).
- Spesso nascondono un bug latente: ogni caso viene esaminato e sottoposto singolarmente.

**F. Membri non definiti — potenziali bug reali, SOTTOPORRE**
- `$primary_key` su `AbstractRelationship`/`BelongsTo`, `$id` su `Model`, `$conn` su `Connection`, `create_column()`/`set_keys()` non trovati, chiamate `static::` a metodi privati (fix `self::`).

### Verifica (definition of done, PR #1)
- `composer run test` (Docker) verde — ricorda `--fail-on-risky/--warning/--skipped/--deprecation`.
- `composer run analyse` verde con baseline ridotta.
- Nessuna nuova voce aggiunta alla baseline per silenziare errori risolvibili (per la regola del CLAUDE.md: il nuovo codice non deve aggiungere soppressioni).

---

## Workstream 2 — Coding style PER-CS 3.0 (PR #2)

### Tooling
- `composer.json` `require-dev`: **rimuovo** `squizlabs/php_codesniffer`, **aggiungo** `friendsofphp/php-cs-fixer`.
- Nuovo `.php-cs-fixer.dist.php`:
  - Ruleset: `@PER-CS3.0` (**solo non-risky**).
  - Finder su: `lib/`, `test/`, `examples/`, e i file PHP di root (es. `ActiveRecord.php`).
  - Cache file (`.php-cs-fixer.cache`) aggiunto a `.gitignore`.
- Script composer:
  - `cs`: `php-cs-fixer fix --dry-run --diff` (check, usato in CI).
  - `cs-fix`: `php-cs-fixer fix` (applica).

### Applicazione
- Un **commit meccanico isolato** con `composer run cs-fix` su tutto lo scope (tab → 4 spazi e resto delle regole PER-CS 3.0).

### CI
- Nuovo step nel job PHP 8.3 (accanto a `composer run analyse`) che gira `composer run cs` e **fallisce** la build se lo stile non è conforme.
- Nessuna modifica al Dockerfile: `vendor/bin/php-cs-fixer` è fornito da `composer install` (dev dep) nell'immagine `tests`.

### Documentazione
- Aggiorno `CLAUDE.md`:
  - Sezione "Conventions" — la descrizione "tabs for indentation / don't modernize wholesale" diventa "**4 spazi**, enforced da PHP-CS-Fixer `@PER-CS3.0`".
  - Sezione "Commands" — aggiungo `composer run cs` / `composer run cs-fix`.
  - Resta invariata la regola snake_case per l'API pubblica e i metodi di test (`set_up`, `test_*`): PER-CS è formattazione, non naming, quindi non tocca i nomi.

### Verifica (definition of done, PR #2)
- `composer run cs` verde (conformità su tutto lo scope).
- `composer run test` (Docker) verde dopo il reformat (le regole non-risky non cambiano semantica).
- `composer run analyse` ancora verde (whitespace non tocca la baseline).

---

## Rischi & mitigazioni
- **Diff enorme** (soprattutto `test/`): commit meccanico isolato; review con `git diff -w`.
- **Fix gruppo E**: un ramo "morto" potrebbe essere raggiungibile via tipi dinamici → review caso-per-caso + suite completa come rete.
- **Regressioni da reformat**: mitigate scegliendo solo regole non-risky e rieseguendo l'intera suite.

## Fuori scope
- Alzare il livello PHPStan oltre il 5.
- Regole PER-CS `:risky`.
- Refactoring/modernizzazione del codice legacy oltre a formattazione e fix PHPStan concordati.
