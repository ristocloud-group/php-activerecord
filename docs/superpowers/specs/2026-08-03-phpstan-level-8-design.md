# PHPStan livello 8 — design

**Data:** 2026-08-03
**Stato:** approvato (design), in attesa del piano di implementazione

## Obiettivo

Portare l'analisi statica PHPStan della libreria da **livello 5** a **livello 8**, risolvendo le segnalazioni in modo incrementale e senza introdurre regressioni di backward-compatibility (la libreria è vendored in altre applicazioni).

## Fotografia empirica di partenza

Rilevata lanciando `phpstan analyse lib --level=N` (config attuale: livello 5, baseline con 6 voci):

| Livello | Errori totali | Delta rispetto al precedente |
|---|---|---|
| 6 | 513 | +507 (bulk `missingType.*`) |
| 7 | 586 | +73 (narrowing union / rami morti) |
| 8 | 633 | +47 (accesso a metodi/proprietà su nullable) |

Distribuzione al livello 8 per tipo di errore:

| Identifier | Conteggio | Natura |
|---|---|---|
| `missingType.return` | 171 | manca `@return`/tipo di ritorno |
| `missingType.parameter` | 147 | manca tipo parametro |
| `missingType.iterableValue` | 123 | manca `array<K,V>` nei docblock |
| `missingType.property` | 68 | manca tipo proprietà |
| `method.nonObject` / `method.notFound` | 54 | chiamata su tipo sbagliato/`object` |
| `argument.type` | 25 | argomento con tipo incompatibile |
| `property.notFound` / `offsetAccess.*` | 29 | accesso proprietà/offset non tipati |
| `return.type` | 8 | ritorno incompatibile con la firma |
| altri | ~8 | vari |

Distribuzione per file (top): `Table.php` 94, `SQLBuilder.php` 79, `Relationship.php` 78, `Validations.php` 63, `Model.php` 59, `Utils.php` 52, `Serialization.php` 40, `Connection.php` 29, `Expressions.php` 28. Questi coprono ~l'80% del totale.

**Insight chiave:**
1. ~80% delle segnalazioni sono annotazioni di tipo mancanti (`missingType.*`), risolvibili quasi interamente con **PHPDoc** (`@param`/`@return`/`@var`, array-shape) → **rischio BC runtime nullo**.
2. Gran parte del restante ~20% "type-safety" è **a cascata da poche cause radice** non tipate — es. `Inflector::instance()`, `CallBack::$klass`, il cast a `object` dell'URL in `Connection`. Tipizzarne una manciata elimina decine di errori derivati.
3. Il residuo di **bug potenzialmente reali** è piccolo ma va trattato caso per caso, alcuni toccano return type di API pubblica (hard gate BC).

## Decisioni di design

| Dimensione | Scelta | Motivazione |
|---|---|---|
| Sequenziamento | Salita incrementale 6 → 7 → 8 | Gradini realistici e rivedibili; 8 resta il traguardo |
| Rischio BC | **PHPDoc-first**, tipi nativi solo dove sicuro | La libreria è vendored; PHPDoc non cambia le firme runtime ma PHPStan lo onora |
| Granularità PR | **Un PR per livello** | Meno overhead; il PR-A resta leggibile grazie ai commit per-file |
| Bug reali | **PR separati + test (TDD)** | Escono dal flusso "tipi"; stop e conferma sui casi che toccano la BC |

## Architettura dell'intervento

Tre PR sequenziali "gradino", uno per livello. Ogni PR bumpa il livello in `phpstan.neon` **solo quando l'analisi a quel livello è a zero errori**. La baseline attuale (6 voci di livello 5) resta invariata; **non si aggiungono nuove voci di baseline** — l'obiettivo di ogni gradino è zero senza baseline.

In parallelo, **PR-bug dedicati** si staccano dal flusso man mano che i tipi rivelano bug veri.

### PR-A — Livello 6 (~513 errori)

Regola operativa **PHPDoc-first**:

- `missingType.return/parameter/property/iterableValue` → `@return`/`@param`/`@var` e array-shape (`array<string,mixed>`, `list<...>`) nei docblock → zero BC runtime.
- Tipi **nativi** solo su metodi/proprietà `private`/interni, dove non esiste override né firma pubblica esposta.
- **Cause radice a cascata** da tipizzare: `Inflector::instance()` (→ `Inflector`), `CallBack::$klass` (→ `ReflectionClass`), il cast a `object` dell'URL in `Connection`.
- Se una firma pubblica richiederebbe un tipo nativo per essere corretta e ciò romperebbe la BC → **stop**, discussione col maintainer, in genere PHPDoc + nota.

Anche se è un PR unico, i **commit sono per-file** nell'ordine di concentrazione: Table → SQLBuilder → Relationship → Validations → Model → Utils → coda. La review resta leggibile commit-per-commit.

### PR-B — Livello 7 (+73 errori)

- Guardie `instanceof`/`assert`, raffinamento delle union, rimozione dei rami morti segnalati.
- Attenzione ai "rami morti" che sono in realtà bug logici → in quel caso diventano PR-bug con test.

### PR-C — Livello 8 (+47 errori)

- Null-guard / nullsafe sugli accessi a metodi/proprietà su tipi nullable.
- Il bug `DateTime|false` a `Connection.php:527` esce come PR-bug con test.

### PR-bug (paralleli, on-demand)

Ogni bug vero: PR separato, **test di regressione in TDD** (fallisce prima del fix, passa dopo). I casi che toccano la BC (return type pubblici) sono **gated** su approvazione esplicita del maintainer.

Candidati già individuati:

| Punto | Problema | BC? |
|---|---|---|
| `Connection.php:527` | `format()` su `DateTime\|false` (createFromFormat non gestito) | no |
| `Inflector::underscorify` | ritorna `string\|null`, dichiara `string` | no |
| `Connection.php:206` | `substr` con `string\|null` | no |
| `Reflections.php:36` | `class-string` non garantita | no |
| `Model::to_json/to_xml/to_csv/to_array` | dichiara `string`/`array`, ritorna `array\|string` | **sì → stop e conferma** |

## Verifica (per ogni PR)

- `composer run test` (suite completa — il gate CI) **verde**: è il regression guard per le modifiche di solo-tipo.
- `composer run analyse` al livello target → **0 errori**.
- `composer run cs` (stile PER-CS 3.0 + PHP 8.3 migration) verde.
- PHP 8.5 lasciato alla CI (la suite locale gira su 8.3); coerente con la policy nota del progetto.
- Bug fix in **TDD**: test che fallisce prima, passa dopo.

## Skill di implementazione

- **php-pro** — regola standing per il codice PHP.
- **test-driven-development** — per i PR-bug.
- **mysql** — se un fix tocca SQL.

## Rischi e mitigazioni

| Rischio | Mitigazione |
|---|---|
| Tipi nativi su API pubbliche rompono la BC | PHPDoc-first; stop e conferma sui casi dubbi |
| Array-shape troppo stretti generano nuovi errori downstream | Iterazione; verificare l'intero livello a zero prima del bump |
| PR-A grande e faticoso da revisionare | Commit per-file nell'ordine di concentrazione |
| Un "ramo morto" segnalato è in realtà un bug logico | Trattarlo come PR-bug con test, non come semplice pulizia |

## Definizione di fatto

- `phpstan.neon` al livello **8**.
- `composer run analyse` → **0 errori**, senza nuove voci di baseline.
- Suite completa verde su tutta la matrice CI (PHP 8.3/8.4/8.5 × MySQL/MariaDB/Postgres/SQLite).
- I bug reali individuati o corretti (con test) o esplicitamente rinviati con nota concordata col maintainer.
