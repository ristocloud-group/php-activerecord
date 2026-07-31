# PHPStan Reduction Implementation Plan (PR #1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ridurre gli errori PHPStan (livello 5) risolvendo tutti i casi sicuri e sottoponendo al maintainer, uno per uno, ogni fix breaking o sospetto; i casi tosti restano in una `phpstan-baseline.neon` ridotta.

**Architecture:** Si lavora per gruppi (A–F) definiti nello spec. I fix meccanici/documentali si applicano direttamente; i fix che toccano API pubbliche, tipi di proprietà, dead code o membri non definiti passano da un gate di approvazione del maintainer con failure-scenario. Verifica a ogni task: il conteggio errori PHPStan deve scendere e la suite completa deve restare verde. La baseline viene rigenerata solo alla fine, contenente unicamente il residuo non risolto/rifiutato.

**Tech Stack:** PHP 8.3+, PHPStan 2.x (livello 5), PHPUnit 12, Docker Compose (MySQL/MariaDB/Postgres/memcached).

## Global Constraints

- PHP floor `^8.3`; codice nuovo in PHP moderno (short array, type declarations, `??`/`?->`), ma edit minimali e behavior-preserving sulle hot path legacy.
- API pubblica in **snake_case** — non rinominare.
- **Backward compatibility è un gate duro**: ogni cambio di firma pubblica, tipo di ritorno/eccezione, o comportamento osservabile va sottoposto al maintainer PRIMA di applicarlo (Task 4–7).
- **Non aggiungere** `@phpstan-ignore` / voci di baseline per silenziare errori risolvibili. La baseline finale conterrà solo ciò che il maintainer ha esplicitamente deciso di non toccare.
- Livello PHPStan resta **5** (no scope creep).
- Verifica sempre in Docker: `docker compose exec tests composer run test` (con `--fail-on-risky/--warning/--skipped/--deprecation`) e `docker compose exec tests composer run analyse`.
- Usare la skill `php-pro` per i fix PHP.
- Branch dedicato per questa PR; lo spec (`docs/superpowers/specs/2026-07-31-phpstan-cleanup-and-per-cs-3-style-design.md`) va incluso nel primo commit di questo branch.

---

## Working method (vale per ogni task)

Non è un flusso TDD tradizionale: lo "strumento di test" è `composer run analyse`. Per iterare senza la baseline che nasconde gli errori, si usa una config temporanea:

```sh
# creare una volta all'inizio, NON committare
cat > phpstan-nobaseline.neon <<'EOF'
parameters:
    level: 5
    paths:
        - lib
    bootstrapFiles:
        - ActiveRecord.php
EOF
```

Comando di misura (conteggio errori correnti, senza baseline):
```sh
docker compose exec tests vendor/bin/phpstan analyse --memory-limit=1G --no-progress \
  --error-format=raw -c phpstan-nobaseline.neon 2>&1 | grep -cE '/lib/.*:[0-9]+:'
```
Baseline attuale al via: **137 errori**. Ogni task dichiara di quanto deve scendere il conteggio.

Regola di sicurezza per Task 4–7 (gate): per ogni item presentare al maintainer, in un unico messaggio, **(a)** file:riga + messaggio PHPStan, **(b)** il codice attuale, **(c)** il fix proposto, **(d)** failure-scenario concreto (input/stato → output errato) o motivo per cui è un falso positivo, **(e)** se è breaking e chi impatta. Applicare solo dopo un "sì". Ciò che il maintainer rifiuta → resta in baseline.

---

### Task 0: Setup branch + spec

**Files:**
- Create: `phpstan-nobaseline.neon` (locale, non committato — aggiungere a `.gitignore` NON serve: non va committato e basta)
- Include: `docs/superpowers/specs/2026-07-31-phpstan-cleanup-and-per-cs-3-style-design.md`

- [ ] **Step 1: Creare il branch**

```bash
git checkout -b phpstan-reduction
```

- [ ] **Step 2: Committare lo spec di design**

```bash
git add docs/superpowers/specs/2026-07-31-phpstan-cleanup-and-per-cs-3-style-design.md
git commit -m "docs: add PHPStan cleanup + PER-CS 3.0 design spec"
```

- [ ] **Step 3: Creare la config temporanea e misurare il baseline di partenza**

Creare `phpstan-nobaseline.neon` (contenuto sopra) e lanciare il comando di misura.
Expected: `137`.

---

### Task 1: Group A — correzioni docblock/type non breaking

Tutti fix **documentali o FQCN**, nessun cambio di comportamento a runtime.

**Files (Modify):**
- `lib/Exceptions.php:88` — `@param str $property_name` → `@param string $property_name`
- `lib/Exceptions.php:114-115` — `@param str $class_name` / `@param str $method_name` → `@param string ...`
- `lib/Utils.php:78,82` — docblock di `denamespace()`: il `@param string $class` referenzia un parametro inesistente; il parametro reale è `$class_name` → `@param string $class_name`
- `lib/Utils.php:120` — `all()`: `@return unknown_type` → `@return bool`
- `lib/SQLBuilder.php:212` — `create_conditions_from_underscored_string()`: `@return A conditions array ...` → `@return array|null` (il metodo ritorna `null` a riga 217 e un array altrimenti)
- `lib/Connection.php:503,511` — `@return PDOStatement` → `@return \PDOStatement` (query_column_info / query_for_tables)
- `lib/Connection.php:264,362` — riferimenti a `PDOStatement` nel corpo/PHPDoc → `\PDOStatement`
- `lib/adapters/MysqlAdapter.php:25,30` — `@return PDOStatement` → `@return \PDOStatement`
- `lib/adapters/PgsqlAdapter.php:37,67` — idem
- `lib/adapters/SqliteAdapter.php:34,39` — idem
- `lib/Config.php:108` — `@throws ActiveRecord\ConfigException` scritto come doppio namespace → `@throws ConfigException` (senza prefisso `ActiveRecord\`, il file è già nel namespace)
- `lib/Model.php:709` — `@throws ActiveRecord\ReadOnlyException` → `@throws ReadOnlyException`
- `lib/Model.php:1168` — `@throws ActiveRecord\UndefinedPropertyException` → `@throws UndefinedPropertyException`
- `lib/Model.php:399,478,1317,1521,1585,1646` — `@throws`/`@param` con sintassi `{@link X}`: rimuovere `{@link ...}` dalla posizione del tipo. Es. `@throws {@link RecordNotFound} if ...` → `@throws RecordNotFound ...`; per `@param` (riga 581) `@param boolean Set to true...` → `@param bool $singular Set to true...` (aggiungere il nome variabile mancante — verificare il nome reale del parametro leggendo la firma)
- `lib/Validations.php:759` — `@return string/array Array of strings...` → `@return string|array Array of strings...`

- [ ] **Step 1: Applicare tutte le sostituzioni sopra** (skill `php-pro`; ogni edit è documentale o aggiunta di backslash, nessuna logica toccata)

- [ ] **Step 2: Misurare il calo**

```sh
docker compose exec tests vendor/bin/phpstan analyse --memory-limit=1G --no-progress \
  --error-format=raw -c phpstan-nobaseline.neon 2>&1 | grep -cE '/lib/.*:[0-9]+:'
```
Expected: sceso da 137 a ~110 (≈ -25/27). Se un errore Group A resiste, leggere la riga e correggere il docblock finché sparisce.

- [ ] **Step 3: Suite verde (i docblock non cambiano runtime, ma confermiamo che nulla è rotto da un backslash malmesso)**

```sh
docker compose exec tests composer run test
```
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add lib/
git commit -m "fix(phpstan): correct broken docblock types and \\PDOStatement FQCN (group A)"
```

---

### Task 2: Group B — return type / void mismatch behavior-neutral

Fix di firma/return dove il comportamento a runtime non cambia (o cambia solo un cast già implicito). **NB:** i casi con effetto osservabile (`count()` cast, `to_array()`) vanno prima verificati nei loro test esistenti; se il cast cambia un valore restituito a un consumer, spostarli al gate di Task 4.

**Files (Modify) — candidati:**
- `lib/Model.php` — metodi dichiarati `void` che eseguono `return $this`/`return $x`: `__set`, `__clone`, `set_relationship_from_eager_load`, `drop_connection`. Fix: rimuovere il valore dal `return` (usare `return;` o togliere il `return` a fine metodo) **preservando i punti di early-return**.
- `lib/Model.php:1431` — `count()` dichiara/documenta `int` ma ritorna `string` (risultato COUNT da PDO). Fix: `return (int) $result;`
- `lib/Model.php:1772` — `to_array()` return type mismatch: verificare il ramo che ritorna `string` e allineare (leggere il metodo; probabile che un ramo debba ritornare array).
- `lib/Serialization.php:253` — `ArraySerializer::to_s()` dichiara `string` ma ritorna array: allineare return type o ramo.
- `lib/Config.php:140` — `get_connection()` dichiara `string` ma può ritornare `null`: cambiare return type/docblock a `?string` (verificare che nessun chiamante assuma non-null — se sì → gate Task 4).
- `lib/Connection.php:433` — `next_sequence_value()` `string` ma ritorna `null` → `?string`.
- `lib/Connection.php:482` — `string_to_datetime()` `DateTime` ma ritorna `null` → `?DateTime` (FQCN corretto `ActiveRecord\DateTime`).
- `lib/Model.php:604` — `get_real_attribute_name()` `string` ma ritorna `null` → `?string` (verificare i chiamanti: se assumono string non-null → gate).

- [ ] **Step 1: Per ciascun metodo, leggere il corpo e classificarlo** neutrale (applica) vs osservabile (sposta a Task 4). Annotare la classificazione nel messaggio di commit.

- [ ] **Step 2: Applicare i fix neutrali** (skill `php-pro`).

- [ ] **Step 3: Suite verde** — `docker compose exec tests composer run test`. Expected: PASS. Un fallimento qui rivela che il fix era osservabile → revert quel singolo fix e spostalo a Task 4.

- [ ] **Step 4: Misurare il calo** (comando di misura). Expected: ulteriore calo (~ -12/15).

- [ ] **Step 5: Commit**

```bash
git add lib/
git commit -m "fix(phpstan): align void/nullable return types (group B, behavior-neutral)"
```

---

### Task 3: Group C — tipo `ActiveRecord\Relationship` inesistente [GATE]

Il codice riferisce un tipo `Relationship` che non esiste; le classi reali sono `AbstractRelationship`/`HasMany`/`HasOne`/`BelongsTo`/`HasAndBelongsToMany`.

**Inventario errori:**
- `lib/Table.php:150` — `construct_inner_join_sql()` on unknown class `Relationship`
- + le voci in baseline: `Table::get_relationship()` return type `Relationship`; `Table::add_relationship()` param type `Relationship`; accessi a `$attribute_name`/`$class_name` su `Relationship`; `Model` che chiama `is_poly()`/`load()`/`get_table()` su `Relationship`.

**Perché è GATE:** cambia la firma dichiarata di `Table::get_relationship()` (return) e `Table::add_relationship()` (param) — API semi-pubblica.

- [ ] **Step 1: Investigare** la gerarchia in `lib/Relationship.php` (quali metodi/proprietà sono realmente sull'antenato comune `AbstractRelationship`).

- [ ] **Step 2: Proporre la sostituzione di tipo** al maintainer (probabile: introdurre/usare `AbstractRelationship` come tipo comune, o una union). Presentare firme prima/dopo di `get_relationship()`/`add_relationship()` + failure-scenario/impatto backward-compat.

- [ ] **Step 3: Applicare la scelta approvata** (skill `php-pro`). Se rifiutata → lasciare in baseline.

- [ ] **Step 4: Suite verde + misura calo.** `docker compose exec tests composer run test` PASS.

- [ ] **Step 5: Commit** — `git commit -m "fix(phpstan): use AbstractRelationship type across Table/Model (group C)"` (adattare al fix approvato).

---

### Task 4: Group B osservabili + Group D — tipi di proprietà troppo stretti [GATE]

**Inventario errori (Group D):**
- `lib/Relationship.php:46` — `AbstractRelationship::$foreign_key (string)` default value array
- `lib/Relationship.php:96,473,643` — `$foreign_key (string)` riceve `array`
- `lib/adapters/PgsqlAdapter.php:109` — `Column::$sequence (bool)` riceve `string`
- baseline: `Column::$length (int)` non accetta null; `Table::$conn (Connection)` non accetta null; `Model::$__dirty (array)` non accetta null
- + eventuali Group B spostati qui da Task 2.

**Perché è GATE:** allargare il tipo di una proprietà pubblica (`$foreign_key` → `string|array`, `$length` → `?int`, ecc.) è corretto ma cambia il contratto della proprietà.

- [ ] **Step 1: Per ogni proprietà, verificare i valori realmente assegnati** (grep degli assegnamenti) e determinare il tipo corretto.

- [ ] **Step 2: Presentare al maintainer** l'elenco proprietà con tipo attuale → tipo proposto + failure-scenario + impatto.

- [ ] **Step 3: Applicare gli approvati** (skill `php-pro`); rifiutati → baseline.

- [ ] **Step 4: Suite verde + misura.** PASS.

- [ ] **Step 5: Commit** — `git commit -m "fix(phpstan): widen property types to match assignments (group D)"`.

---

### Task 5: Group E — dead code / condizioni sempre vere-false [GATE, il più delicato]

**Inventario errori:**
- `lib/Relationship.php:337` — `instanceof HasOne` sempre falso (dentro `AbstractRelationship`)
- baseline: `Model.php` `if.alwaysTrue` ×2, `deadCode.unreachable` ×2, `booleanOr.leftAlwaysTrue`/`rightAlwaysTrue`, espressione `$this->{$assoc}` su riga isolata; `Relationship.php` `if.alwaysTrue`, `booleanNot.alwaysFalse`, `deadCode.unreachable`; `SQLBuilder.php` `booleanNot.alwaysFalse`; `Validations.php` `booleanNot.alwaysFalse` ×2, `is_null(string)`/`is_numeric(float)` sempre veri/falsi, espressione `array($enum)` isolata; `Table.php`/`SqliteAdapter.php` `empty()` su variabile sempre esistente; `Config.php`/`Model.php` `is_array()` sempre vero.

**Perché è GATE (massima attenzione):** una condizione "sempre vera" o un ramo "morto" spesso segnalano un **bug latente** — il controllo doveva fare qualcosa. Rimuovere il ramo può cambiare comportamento se il tipo dinamico reale differisce da quanto PHPStan inferisce.

- [ ] **Step 1: Per ogni item, leggere il contesto** e formulare l'ipotesi: (i) morto davvero, rimuovibile; (ii) sintomo di bug → il fix corretto è correggere la logica, non cancellare il ramo.

- [ ] **Step 2: Presentare al maintainer OGNI item** con failure-scenario e la raccomandazione (rimuovi vs correggi). Uno per uno.

- [ ] **Step 3: Applicare gli approvati.** Dove il fix corregge un bug reale, **aggiungere un test di regressione** in `test/` (fixture/model esistenti) che fallisce prima e passa dopo. Rifiutati/dubbi → baseline con commento.

- [ ] **Step 4: Suite verde + misura.** PASS.

- [ ] **Step 5: Commit** — `git commit -m "fix(phpstan): resolve dead-code/always-true conditions (group E)"`.

---

### Task 6: Group F — membri/metodi non definiti [GATE]

**Inventario errori:**
- `lib/Relationship.php:153,166,355` — `AbstractRelationship::$primary_key` non definita
- `lib/Relationship.php:156` — `AbstractRelationship::set_keys()` non definito
- baseline: `BelongsTo::$primary_key`, `Model::$id`, `Connection::$conn`, `Connection::create_column()`, chiamate `static::` a metodi privati (`Cache::get_namespace`, `Connection::load_adapter_class`)
- `lib/CallBack.php:162` — `get_class()` con string; `CallBack::$publicMethods` isset non-nullable
- `lib/Validations.php:362` — `str_replace` con float; `Errors::__get` ritorna null; `Errors::to_array` param Closure; variabili `$enum`/`$range_option` possibly undefined

**Perché è GATE:** alcuni sono falsi positivi risolvibili dichiarando la proprietà (`$primary_key`, `$publicMethods`) o correggendo la visibilità/`self::`; altri (`Model::$id`, variabili undefined, `str_replace(float)`) possono essere bug reali.

- [ ] **Step 1: Classificare ogni item**: (a) falso positivo → dichiarare proprietà tipata / `self::` al posto di `static::`; (b) bug reale → correggere.

- [ ] **Step 2: Presentare al maintainer** i (b) e ogni fix che tocca visibilità/firma. I (a) puramente additivi (dichiarazione proprietà, `self::`) si possono applicare direttamente ma vanno elencati nel commit.

- [ ] **Step 3: Applicare** (skill `php-pro`); aggiungere test di regressione dove si corregge un bug.

- [ ] **Step 4: Suite verde + misura.** PASS.

- [ ] **Step 5: Commit** — `git commit -m "fix(phpstan): declare missing members and fix undefined access (group F)"`.

---

### Task 7: Rigenerare la baseline ridotta + verifica finale + PR

- [ ] **Step 1: Rimuovere la config temporanea**

```bash
rm -f phpstan-nobaseline.neon
```

- [ ] **Step 2: Rigenerare la baseline** con solo il residuo non risolto

```sh
docker compose exec tests vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline
```
Verificare che `phpstan-baseline.neon` sia **più piccolo** dell'originale (137 voci → solo i casi rifiutati/tosti). Ogni voce residua deve corrispondere a una decisione esplicita del maintainer.

- [ ] **Step 3: `analyse` verde con baseline**

```sh
docker compose exec tests composer run analyse
```
Expected: `[OK] No errors`.

- [ ] **Step 4: Suite completa verde**

```sh
docker compose exec tests composer run test
```
Expected: PASS (nessun test skipped/risky/deprecated).

- [ ] **Step 5: Commit baseline + push + PR**

```bash
git add phpstan-baseline.neon lib/ test/
git commit -m "chore(phpstan): regenerate reduced baseline"
git push -u origin phpstan-reduction
gh pr create --title "PHPStan reduction (level 5)" --body "<riassunto dei gruppi A-F e delle decisioni del maintainer>"
```

---

## Self-Review

- **Spec coverage:** gruppi A–F dello spec → Task 1–6; baseline ridotta + verifica → Task 7; gate backward-compat → Task 3–7; livello 5 invariato → Global Constraints. ✓
- **Placeholder scan:** i "gate" (Task 3–6) non sono placeholder: il fix dipende legittimamente da una decisione del maintainer e l'inventario errori è concreto (file:riga). Group A/B hanno edit espliciti. ✓
- **Type consistency:** i nomi di metodo/proprietà citati provengono dall'output PHPStan reale. ✓
- **Nota:** l'ordine A→B→C→D→E→F è per rischio crescente; il conteggio esatto di calo per task è indicativo (dipende da come PHPStan re-inferisce dopo ogni fix) — l'invariante vero è "il conteggio scende e la suite resta verde".
