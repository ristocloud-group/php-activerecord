# Chiusura delle issue aperte (ottobre 2026) — Report di verifica e decisioni

**Data:** 2026-10-06 · **Base verificata:** `master` = `origin/master` = `583fd8e` · **Plan:** `docs/superpowers/plans/2026-10-06-chiusura-issue-aperte.md`

Questo documento è la spec del plan: dice **cosa** è ancora rotto, con quali prove, e **quale comportamento** il maintainer ha deciso per ogni correzione. Il plan dice in che ordine e con quali passi farlo. Dove il testo di una issue GitHub e questo documento divergono, vale questo documento: è più recente ed è verificato.

## 1. Perimetro e metodo

- **Perimetro:** le 28 issue aperte al 2026-10-06 (26 difetti/feature, l'umbrella Postgres #92 e il tracker #91), più 8 difetti nuovi emersi durante la verifica e aperti come #149–#156 (§5).
- **Come:** sei agenti in parallelo, uno per area, in sola lettura. Ogni affermazione delle issue è stata riprodotta con script autonomi (tabelle proprie `v<issue>_*`, poi rimosse) eseguiti sul codice di `583fd8e` tramite il runner `.superpowers/bin/phpar-test`, che copia l'albero in un container usa-e-getta.
- **Ambiente:** PHP 8.3.35 (controlli mirati su 8.5), MySQL 9.7.2, MariaDB 11.4.12, PostgreSQL 18.6, SQLite 3.46.1, memcached, redis. Le versioni minime della CI sono state provate dove contava, su container temporanei: MySQL 8.4.11, MariaDB 10.11.19, PostgreSQL 15.19. La SQLite della CI (ubuntu-24.04) è la 3.45.1.
- **Uso reale:** il monolite RistoCloud (`~/Documents/Projects/ristocloud`, ancora su `zamzar/php-activerecord` v1.7.1) è stato letto **solo** con grep, per misurare l'impatto di ogni cambio di comportamento. Si riportano solo conteggi e riferimenti `file:riga`.
- **Legenda:** **VALIDA** = riprodotta com'è descritta; **VALIDA+** = riprodotta e più ampia o più grave del testo; **PARZIALE** = in parte smentita; **FEATURE** = non è un difetto.

## 2. Esito in sintesi

Nessuna issue è risolta né obsoleta. Il codice citato si è però spostato (i numeri di riga delle issue sono superati) e diversi testi sottostimano il problema.

| Issue | Tema | Esito | Gravità reale | Effort | Decisione | Task |
|---|---|---|---|---|---|---|
| #145 | `delete_all`/`update_all` senza `conditions` | VALIDA+ | **alta** (perdita dati silenziosa) | M | D1 | T1 |
| #66 | SQLite `INT PRIMARY KEY` preso per rowid | VALIDA+ | **alta su SQLite** (update/delete sulla riga sbagliata) | S–M | D9 | T2 |
| #148 | test `DateTimeTest` instabile | VALIDA+ (altri 8 test) | bassa (CI) | S | — | T3 |
| #33 | `exists`/`count` con pk falsy o multiple | VALIDA+ | media | S–M | D2 | T4 |
| #38 | `first`/`last` ignorano `offset`/`limit` | VALIDA | bassa-media | S | D3 | T5 |
| #55 | pk stringa dopo l'insert | VALIDA | bassa (tipi incoerenti) | S | D4 | T6 |
| #65 | SQLite `tables()` elenca indici/trigger | VALIDA | bassa | S | D10 | T7 |
| #51 | uniqueness ignora `allow_null`/`allow_blank` | VALIDA | bassa-media | S | D6 | T8 |
| #123 | modelli su tabelle senza pk | VALIDA+ | bassa | M | D8 | T9 |
| #49 | numericality perde precisione oltre 2^53 | VALIDA+ | media | M | D5 | T10 |
| #57 | length conta byte invece di caratteri | VALIDA+ | bassa | S–M | D7 | T11 |
| #146 | DECIMAL letti come float | VALIDA+ | media (da 15 cifre) | S–M | D12 | T12 |
| #67 | tabella mancante su PG/SQLite | VALIDA+ | bassa-media | S–M | D11 | T13 |
| #63 | `flush()` ignora il namespace | VALIDA+ (anche Redis) | bassa | M | D20 | T14 |
| #62 | la cache scarta i valori falsy | VALIDA+ | bassa | M | D20 | T15 |
| #92 | Postgres: Cat 1/4/5 + CI | VALIDA (invariata) | media | L | D13 | T16, T21, T22 |
| #147 | PG: introspezione non limitata allo schema | VALIDA+ | bassa | S | D16 | T17 |
| #68 | PG: default estratti male | VALIDA+ | bassa-media | S–M | D15 | T18 |
| #47 | PG: sequence e IDENTITY | VALIDA+ | media | M | D14 | T19 |
| #143 | PG: modelli con `$db` | VALIDA (premessa bytea errata) | media | M | D16 | T20 |
| #144 | alias in count/exists/bulk/relazioni | VALIDA+ | media | M | D18 | T23 |
| #137 | eager con `limit`/`offset` carica tutto | VALIDA+ | bassa oggi (prestazioni) | M–L | D19 | T24 |
| #124 | `to_csv` con `include` scrive "Array" | VALIDA+ | bassa | S | D21 | T25 |
| #142 | validare le chiavi hash prima della query | FEATURE | — | S | D17 (won't-fix) | T26 |
| #48 | inflector: `Human` → `humen` | PARZIALE | bassa | S–M | D23 | T27 |
| #125 | stub PHPStan non caricato dai consumer | VALIDA+ (abort di PHPStan) | bassa (tooling) | S | D22 | T28 |
| #16 | baseline PHPStan (6 voci) | VALIDA | bassa | S | D22 | T36 |
| #91 | tracker dell'audit | meta | — | — | — | chiusura |
| #149–#156 | nuove (§5) | VALIDE | varie | S–M | D24 (gate) | T29–T35, #155 in T9 |

Monolite: nessuna chiamata nelle forme che cambiano comportamento per #145, #33, #38, #142, #144, #137, #124. Le uniche superfici toccate sono il JSON delle pk appena create (#55, 13 punti) e la sua suite di test SQLite (#66, #67). Due modifiche già fatte nel fork da #24 bloccano invece la migrazione del monolite: vedi §6.

## 3. Decisioni del maintainer (2026-10-06)

Vincolanti per i task. Ogni comportamento non elencato qui resta com'è. Un trade-off nuovo, scoperto durante l'implementazione, torna al maintainer.

### Processo

- **P1 — Esecuzione:** sessioni e sub-agenti Claude Code su questo Mac, con lo stack Docker e il runner `phpar-test` già presenti.
- **P2 — Decisioni BC:** prese in questo documento. Gli agenti si fermano solo davanti a trade-off nuovi, o nei task con gate esplicito (ondata 8).
- **P3 — Ponte:** ogni task apre una PR verso `integration/2026-10-issues`. A ogni ondata validata si apre una PR verso `master` con merge commit. Una issue = una PR, con un'unica eccezione: #155 entra nella PR di #123.

### Comportamento

- **D1 — #145.** `delete_all()` e `update_all()` normalizzano le opzioni con le regole dei finder.
  - Un hash che non contiene nessuna chiave di opzione ("hash nudo") diventa `conditions`.
  - Lanciano `ActiveRecordException` prima di qualsiasi SQL: un hash misto (chiavi di opzione insieme a chiavi sconosciute), una lista posizionale non vuota, uno scalare non stringa, `update_all` con chiavi estranee accanto a `set`.
  - `update_all` senza `set` lancia la stessa eccezione di oggi, ma senza il warning PHP.
  - Restano invariati: nessun argomento, `[]`, la stringa SQL, `['conditions' => …]`, `limit`/`order`, `set` + `conditions`.
  - Le opzioni dei finder passate ai bulk (`select`, `joins`, `from`, `offset`, …) restano ignorate come oggi.
  - La condizione stringa `'0'` va in #150.
- **D2 — #33.** `exists()` e `count()` interpretano gli argomenti come `find()`.
  - `0`, `'0'`, `''` e `false` sono valori di pk.
  - Più pk diventano `IN` (`exists` = almeno uno esiste).
  - Una pk DATE passa per la stessa formattazione di `find_by_pk()`.
  - Restano invariati `count()`, `count(null)`, `count([])` (tutte le righe, gh149) e gli hash di opzioni.
  - Il caso pk + conditions va in #149.
- **D3 — #38.** `first()`, `last()` e `find('first'|'last')` onorano un `offset` passato dal chiamante.
  - `last()` conta l'offset dalla fine (ordine invertito + `OFFSET`).
  - Un `limit` qualsiasi diventa 1; `limit` `0`/`'0'` restituisce `null`, coerente con #34.
  - Un offset negativo vale 0.
  - Per has_one, `limit`/`offset` dichiarati restano ignorati sia in lazy sia in eager.
- **D4 — #55.** Dopo l'insert la pk letta da `insert_id()` passa per `Column::cast()`: intero come dopo `find()`, stringa esatta oltre `PHP_INT_MAX` (#131). `Connection::insert_id()` resta invariato (si corregge solo il docblock in `string|false`). In release va come **BREAKING (behavior)**.
- **D5 — #49.** Quando entrambi gli operandi sono interi (int PHP, stringhe intere, stringhe oltre l'int range), il confronto è esatto; tutto il resto resta in float come oggi.
  - Odd/even è corretto oltre 2^53.
  - Il messaggio mostra il limite intero esatto: cambia solo per limiti interi ≥ 1e14.
  - Restano invariati: `'odd' => null` (attivo per sola presenza), `only_integer` stretto, NAN, il troncamento di #59.
- **D6 — #51.** `allow_null` e `allow_blank` vengono onorati.
  - Con l'opzione, la regola si salta se un qualsiasi campo elencato è null o blank.
  - Senza `allow_null`, un campo NULL salta comunque la query: NULL non entra mai in conflitto, come negli indici UNIQUE.
  - Si rimuove `with` dal docblock.
- **D7 — #57.** Si contano i caratteri.
  - Conteggio: `mb_strlen` se l'estensione c'è, altrimenti il conteggio PCRE `/./su`, altrimenti `strlen` (UTF-8 non valido).
  - Le colonne il cui `raw_type` corrisponde a `/binary|blob|bytea/i` restano in byte.
  - Nuova opzione per regola `'encoding' => '8bit'` per forzare i byte.
  - `composer.json` resta invariato.
- **D8 — #123 (+#155).**
  - `reload()` senza pk, o con una pk non caricata, lancia `ActiveRecordException`.
  - Uniqueness:
    - un record nuovo su tabella senza pk viene validato senza auto-esclusione;
    - un record salvato senza pk lancia `ActiveRecordException`;
    - con pk composta l'esclusione avviene su tutte le colonne di pk (#155).
  - `$m->id` su una tabella senza pk è un attributo sconosciuto (`UndefinedPropertyException`, anche nel mass assignment). Cambiano i test che fissano l'attributo `''`.
- **D9 — #66.** `auto_increment` vale true solo per il vero alias del rowid: pk a colonna singola, tipo esattamente `INTEGER`, nessuna riga `origin='pk'` in `pragma index_list`.
  - Una pk non auto-increment omessa resta `null`, e la guardia di #41 rifiuta update e delete.
  - `test_gh183_sqliteadapter_autoincrement` va ribaltato.
- **D10 — #65.** Su SQLite `tables()` elenca tabelle e viste, esclusi indici, trigger e gli oggetti `sqlite_*`, filtrati con `LIKE` con escape.
  - MySQL e PG restano invariati; la divergenza di PG (niente viste) è documentata.
  - Le tabelle temporanee restano come le riporta ciascun server.
- **D11 — #67.** Su PG e SQLite una tabella inesistente fa lanciare `DatabaseException("Table or view not found: <nome>")` dall'introspezione stessa, quindi l'errore non finisce in cache.
  - Su PG una relazione esistente con zero colonne (la verifica `to_regclass` la risolve) restituisce `[]` come oggi.
  - MySQL e MariaDB restano invariati.
- **D12 — #146.** I float vengono legati con `max(15, ini precision)` cifre significative; con `precision = -1` si usa il round-trip più corto.
  - Tipo degli attributi e JSON restano invariati.
  - Oltre 15 cifre il limite resta, documentato.
  - Le colonne `REAL` tipizzate STRING vanno in #152.
- **D13 — #92.**
  - Cat 1: `Table` mappa nome attributo → nome reale della colonna su INSERT/UPDATE/DELETE e sulle condizioni di pk, con SQL identico per le colonne snake_case. Corregge anche le colonne con trattino o spazio su tutti gli adapter.
  - Cat 4: nessun cambio a runtime, si documenta 22P02 e l'abort della transazione.
  - Cat 5: i 4 skip diventano asserzioni adapter-aware; su PG `limit`/`order` nei bulk restano ignorati, ed è documentato.
  - CI: step pgsql in tutte le 12 celle della matrice; si corregge `README.md:45`.
  - Si dichiara PostgreSQL 15 come versione minima.
- **D14 — #47.** Il pacchetto `Column` ha `$sequence` reale e un nuovo `$identity`.
  - Precedenza per la sequence:
    1. `static $sequence` dichiarata;
    2. `Column::$sequence` della pk (`pg_get_serial_sequence`, oppure la sequence di un default `nextval` non owned);
    3. la convenzione.
  - IDENTITY ALWAYS: INSERT senza pk e lettura con currval.
  - I nomi sono resi come la tabella: relativi al `search_path` per i modelli normali, qualificati per `$db`.
  - `next_sequence_value()` raddoppia gli apici.
  - Le pk con default non-sequence (uuid) vanno in #153.
- **D15 — #68.** `query_column_info()` restituisce anche il testo grezzo del default e `attgenerated`; un parser PHP privato produce `Column::$default`.
  - `DEFAULT 0` → `0`, `DEFAULT ''` → `''`, `DEFAULT NULL` → `null`, i letterali vengono de-escapati.
  - Espressioni e colonne generated → nessun default.
- **D16 — #147/#143.**
  - L'introspezione PG usa `to_regclass`; una tabella fuori dal `search_path` ha 0 colonne (poi D11).
  - `$db` e `'"schema".tabella'` vengono introspettati nel loro schema; la firma di `query_column_info()` resta invariata.
  - numeric segue D12 (float, come i modelli normali).
  - bytea diventa una stringa binaria per **tutti** i modelli PG.
- **D17 — #142.** Won't-fix: una frase nel README ("Hash conditions") e test di guardia sulle chiavi nascoste o generate.
- **D18 — #144.** Regola della "colonna viva": nei percorsi count, exists, update_all (`set` e `conditions`), delete_all e condizioni hash delle relazioni, un alias viene mappato sulla sua colonna solo se il DB conferma che la tabella non ha una colonna con quel nome.
  - Verifica: `SELECT k … LIMIT 0` su MySQL/MariaDB/SQLite, `pg_attribute` su PG.
  - Si memorizzano solo le risposte positive.
  - Restano escluse le condizioni `through` e le query con `from`.
  - `find`/`all`/`first`/`last` e i finder dinamici restano invariati; la differenza è documentata.
- **D19 — #137.** Finestra `ROW_NUMBER()` per le has_many con `limit`/`offset` in eager, con tie-breaker sulla pk del figlio.
  - Si ricade sul percorso di oggi (SQL identico) per: chiavi testuali su MySQL/MariaDB, `select`/`group`/`having` dichiarati, ordinali nell'`ORDER BY`, SQLite < 3.25.
  - has_one resta invariato; la colonna `ar_rn` non deve comparire negli attributi.
- **D20 — #62/#63.**
  - #62:
    - `Cache::get` riconosce un hit anche per i valori falsy tramite un metodo di presenza negli adapter inclusi; il formato in cache resta invariato.
    - `null` continua a significare "non mettere in cache".
    - Gli adapter custom senza il metodo si comportano come oggi.
    - #67 va prima.
  - #63, `flush()` con namespace:
    - Memcache usa una chiave di generazione;
    - File cancella solo i file `<ns>::*`;
    - Redis fa l'escape dei caratteri glob.
  - Senza namespace `flush()` svuota tutto, come oggi.
- **D21 — #124.** `to_csv` con `include`, o con `methods` che restituiscono array, lancia `ActiveRecordException` con un rimando a `to_json()`/`to_array()`. Si toglie anche il warning soppresso su `only_header`.
- **D22 — #125/#16.**
  - #125:
    - `extension.neon` con `parameters.stubFiles` e `extra.phpstan.includes` → `extension.neon`;
    - `type` resta `library`;
    - sezione README "Static analysis" e job CI di smoke test;
    - le PHPDoc autosufficienti vanno in #156.
  - #16: si eliminano tutte e 6 le voci senza cambi a runtime (PHPDoc, rami irraggiungibili, codice morto) e poi si cancella la baseline. È l'ultimo task di codice.
- **D23 — #48.** Lista curata di plurali irregolari e guardia sui singolari. Il `\b` proposto nell'issue è scartato; il bug `ex$` va in #151.
- **D24 — nuove issue #149–#156.** Sono aperte e tracciate in #91. Ogni task della loro ondata parte da una decisione BC che il controller chiede al maintainer prima di iniziare. #155 è risolta nel task di #123.
- **D25 — blocchi del monolite (§6).** Il fork non cambia: li documenta la guida di upgrade (T37).

## 4. Schede per issue

I numeri di riga si riferiscono a `583fd8e`. Script e output grezzi delle verifiche sono nello scratchpad della sessione del 2026-10-06; qui riportiamo l'output essenziale.

### 4.1 Scritture e finder

#### #145 — `delete_all()`/`update_all()` senza `conditions`

- **Esito:** VALIDA+ su MySQL, MariaDB, PG e SQLite (PHP 8.3 e 8.5). Le forme distruttive sono sei, non solo l'hash nudo.
- **Evidenza** (tabella con 3 righe, ricreata a ogni chiamata):
  ```
  count(['name' => 'a'])                         => 1   … WHERE `name`=?
  delete_all(['name' => 'a'])        hash nudo   => 3   DELETE FROM `v145_books`
  delete_all(['name = ?', 'a'])      lista       => 3   DELETE FROM `v145_books`
  delete_all(['condition' => …])     refuso      => 3
  delete_all(['name'=>'b','limit'=>1]) misto    => 1   DELETE … LIMIT 1   ← riga ARBITRARIA (PG: tutte e 3)
  delete_all(2)                      intero      => 3   … WHERE 2           (PG: errore 42804)
  update_all(['set'=>['name'=>'z'], 'name'=>'a']) => 3 righe aggiornate
  update_all(['name'=>'z'])  senza set → warning "Undefined array key set" + ActiveRecordException
  update_all("name = 'z'")             → TypeError
  ```
- **Dove:**
  - `lib/Model.php:1090-1115` (`delete_all`; riga 1096 legge solo `['conditions']`) e `:1146-1176` (`update_all`; `$options['set']` alla riga 1153).
  - In `SQLBuilder`, `build_delete`/`build_update` emettono il WHERE solo `if ($this->where)` (righe 627 e 732).
  - I finder normalizzano invece con `extract_and_validate_options()` (2301-2322) e `is_options_hash()` (2261-2278).
- **Fatti nuovi:**
  - In v1.7.1 la stessa chiamata emetteva `Undefined array key "conditions"` prima della DELETE. #108 ha aggiunto `?? null` e ha tolto anche quel segnale, quindi oggi la cancellazione totale è silenziosa.
  - Il docblock di `delete_all` (riga 1076) contiene una stringa non chiusa.
  - Il docblock di `exists()` documenta proprio l'hash nudo.
- **Soluzione (D1):**
  - Un helper privato di normalizzazione dopo `update_all()`.
  - Le forme documentate restano byte-identiche.
  - Le nuove eccezioni sono `ActiveRecordException` con lo stesso testo `"Unknown key(s): …"` che usa già `is_options_hash()`.
  - Nessun tipo nuovo nelle firme: il monolite fa override non tipizzati di `delete_all`/`update_all` (`RCModel.php:729/743`).
- **Monolite:**
  - 293 `::delete_all(`: 288 con solo `conditions`, 2 svuotamenti voluti senza argomenti, 3 passthrough.
  - 153 `::update_all(`: 145 con `set` + `conditions`, 7 con solo `set` (voluti, 5 migration), 1 passthrough.
  - Nessuna chiamata nelle forme che cambiano.
  - Controllo consigliato: cercare nei log di produzione `Undefined array key "conditions"` su `zamzar/php-activerecord/lib/Model.php:931` (la firma di v1.7.1).

#### #33 — `exists()`/`count()` con pk falsy o multiple

- **Esito:** VALIDA+ (MySQL, PG, SQLite; PHP 8.3 e 8.5). In più c'è un falso negativo: `exists(999, 1) === false` anche se il record 1 esiste.
- **Evidenza:**
  ```
  exists(0) => true / count(0) => 3     (nessun WHERE)   ; idem '0', '', false
  exists(999, 1) => false               … WHERE `id`=?   (il secondo argomento è perso)
  count(1, 2, 3) => 1                   … WHERE `id`=?
  V33Code::count('0') => 3 (pk stringa: atteso 1)        ; find('0') trova la riga
  SQLite, pk DATE: find(DateTime) trova; count/exists(DateTime) => 0/false
  ```
- **Dove:**
  - `lib/Model.php:1965-1978` `finder_conditions_from_args()`: alla riga 1969 il test `!empty($args[0])`; alla 1973 `call_user_func_array` su `pk_conditions($args)` (2287-2292), che accetta un solo argomento.
  - Confronta con `find_by_pk()` (2169-2196) e `date_pk_finder_value()` (2210-2220).
- **Cosa resta invariato:** `count(null)` e `count([])` contano tutte le righe dal 2011 (`test_gh149_empty_count`, `ActiveRecordFindTest.php:291-296`).
- **Soluzione (D2):** si riscrive solo la funzione privata.
  - Pk: scalari e liste → `=`/`IN`; i valori passano per `date_pk_finder_value`.
  - Restano invariati: nessun argomento, `[null]`, `[[]]` e un hash come unico argomento.
- **Monolite:** 530 `::count(` e 66 `::exists(`, nessuna con pk scalari. `RCModel::count` scarta da sé i falsy (`RCModel.php:252`): è un bug lato monolite, fuori da questo plan.

#### #38 — `first()`/`last()` ignorano `offset` e `limit`

- **Esito:** VALIDA (MySQL, PG, SQLite; identica in v1.7.1).
- **Evidenza:**
  ```
  first(['offset'=>1, 'order'=>'id asc']) => id 1   … LIMIT 0,1
  first(['offset'=>10])                   => id 1   (atteso null)
  last(['offset'=>1])                     => id 5   … ORDER BY id DESC LIMIT 0,1
  first(['limit'=>0])                     => id 1   (altrove dopo #34 limit 0 = nessuna riga)
  find(2, ['offset'=>1]) => RecordNotFound ; first(2, ['offset'=>1]) => id 2   (incoerenza)
  ```
- **Dove:** `lib/Model.php:2124-2137`: `case 'last'` inverte l'ordine e prosegue nel caso `'first'`, che imposta `limit = 1` e `offset = 0`.
- **Accoppiamento con has_one:** il has_one lazy passa da `find('first')` con le opzioni dichiarate (`lib/Relationship.php:1087-1090`). `test_eager_has_one_ignores_a_declared_limit_and_offset_like_the_lazy_load` (`test/RelationshipEagerLazyParityTest.php:262-267`) fissa che has_one le ignora, quindi `HasMany::load()` deve toglierle per has_one.
- **Offset negativo:** oggi dà la riga 1. Passato così com'è, MySQL dà un errore di sintassi e PG un errore, quindi va trattato come 0.
- **Soluzione (D3):**
  ```php
  case 'first':
      $options['limit'] = in_array($options['limit'] ?? null, [0, '0'], true) ? 0 : 1;
      $options['offset'] = max(0, intval($options['offset'] ?? 0));
  ```
  Più `unset($options['limit'], $options['offset'])` in `HasMany::load()` per has_one.
- **Monolite:** 1378 `::first(`, 72 `::last(`, 26 `::find(`, nessuno con offset o limit. Nessun has_one dichiara limit o offset. Non vanno aggiunti parametri né tipi: due modelli dichiarano `last()` senza parametri (`Manutenzioni_ordine_lavoro.php:267`, `Manutenzioni_richiesta_intervento.php:207`).

#### #55 — pk stringa dopo l'insert

- **Esito:** VALIDA su tutti e 4 gli adapter.
- **Evidenza:**
  ```
  create: '1'  find: 1  ===: false ; to_json create {"id":"1"} vs find {"id":1}
  create(['id' => 50]): '50' su MySQL/MariaDB/SQLite, 50 su PG (lì non si passa da insert_id)
  BIGINT UNSIGNED: …807 create '…807' / find int ; …808 stringa in entrambi (#131)
  ```
- **Dove:**
  - `lib/Model.php:997`: `$this->attributes[$pk] = static::connection()->insert_id($table->sequence);`, scritto senza cast.
  - Il docblock di `Connection::insert_id()` (337-345) dichiara `int`, ma il metodo restituisce la stringa di `lastInsertId()`.
- **Soluzione (D4):** `$column->cast(...)`. Non va usato `assign_attribute()`, che marcherebbe l'attributo dirty. `Column::cast(INTEGER)` mantiene già la semantica di #131.
- **Monolite:**
  - 13 punti restituiscono al frontend il JSON di un modello appena salvato: `"id":"N"` diventa `"id":N`. Esempio: `controllers/admin/manutenzioni/gestione_stato.php:67-69`.
  - 15 confronti stretti su id appena creati oggi danno sempre "diversi" (esempio: `Nuclei.php:1767`); è un bug latente che il fix corregge.
  - Nessun file con `strict_types`.

### 4.2 Validazioni

Nel monolite esistono solo due `validates_presence_of` (`Stagioni.php:66`, `Periodi_festivita.php:26`). Nessuna delle validazioni di questa sezione lo raggiunge.

#### #51 — uniqueness ignora `allow_null`/`allow_blank`

- **Esito:** VALIDA (MySQL, SQLite, PG; PHP 8.5 identico).
- **Evidenza:**
  ```
  name='' allow_blank (+allow_null), esiste ''  → INVALID "Name must be unique"
     SQL: SELECT EXISTS(SELECT 1 FROM `v51_users` WHERE `id` IS NOT NULL AND `name`=?) binds=[""]
  name=NULL senza opzioni → VALID  (binds=[null]: "= NULL" non trova mai nulla)
  [['name','org']] ('bob',NULL) → VALID ; 'with' => '/^x/' ignorato
  'Carol' vs 'carol': MySQL INVALID (collation _ci), SQLite/PG VALID  (preesistente, fuori scope)
  ```
- **Dove:**
  - `lib/Validations.php:571-594`: il docblock, con `with` fasullo alla riga 586.
  - `:595-640`: la funzione. Le opzioni vengono fuse alle righe 597-599 e mai lette; il ciclo dei campi è alle righe 627-632 (`{field}=?` con bind anche di NULL).
  - Gli helper `is_null_with_option()`/`is_blank_with_option()` (676-687) esistono ma non sono usati.
- **Soluzione (D6):**
  - Con l'opzione si salta la regola se un campo qualsiasi è null o blank; un NULL salta comunque la query.
  - La validazione può solo allentarsi.
  - Le condizioni restano posizionali, così non entrano nei percorsi di #142/#144.

#### #123 (+#155) — modelli su tabelle senza pk

- **Esito:** VALIDA+. Le tre rotture riportate restano; #122/#132/#134 hanno solo ridotto i warning.
- **Evidenza:**
  ```
  NdLog::first()->reload()  → warning Undefined array key 0 (Model.php:2290) + Error "Attempt to modify property attributes on array"
  uniqueness su pk-less     → warning (Validations.php:606) + UndefinedPropertyException (anche create() fallisce)
  $m->id = 5; save()        → attributo '' → DatabaseException Unknown column ''
  update/delete su pk-less  → già ActiveRecordException "Cannot update/delete, no primary key defined for: X" (ok)
  NUOVO: Book::first(['select'=>'name'])->reload() → Error anche su tabella CON pk
  NUOVO (#155): pk composta (a,b): (1,2) rinominato 'x' con (1,1,'x') esistente → is_valid() true
     SQL: … WHERE `a` != ? AND `name`=?  binds=[1,"x"]
  ```
- **Dove:**
  - `reload` in `lib/Model.php:1666-1701`, che chiama `find([])` → `[]`.
  - uniqueness in `lib/Validations.php:605-625`.
  - Scorciatoia `id`: `__set` 514-517 (`get_primary_key(true) ?? ''`), lettura 612-623, `__isset` 421-424, guardia di mass assignment 1527-1531.
- **Soluzione (D8):**
  - `reload`: `ActiveRecordException`, con testo nello stile di update/delete.
  - uniqueness: esclusione su tutte le colonne di pk; record nuovo senza pk validato senza esclusione; record salvato senza pk → eccezione.
  - `id`: attributo sconosciuto.
  - Cambiano i test che fissano `''`: `ActiveRecordTest.php:188-196`, `ModelIssetTest.php:164-172`, `StrictMassAssignmentTest.php:152-156` (messaggio `'id'`).
- **Monolite:** tutte le tabelle del dump hanno una pk; nessun modello dichiara `$primary_key`; 241 `reload()`, tutti su modelli con pk.

#### #49 — numericality e interi oltre 2^53

- **Esito:** VALIDA+. Il problema è più ampio del testo: con `only_integer` un int PHP finisce comunque nel cast a float, l'odd/even è sbagliato oltre 2^53, e PHP 8.5 emette un warning.
- **Evidenza:**
  ```
  '9223372036854775806' less_than PHP_INT_MAX → INVALID "…less than 9.2233720368548E+18"
  9007199254740993 equal_to 9007199254740992  → VALID (atteso INVALID)
  BIGINT UNSIGNED '…614' less_than '…615'     → INVALID "…1.844674407371E+19"
  9007199254740993 odd → INVALID "must be odd"
  PHP 8.5: E_WARNING "The float 1.8446744073709552E+19 is not representable as an int" (Utils.php:290)
  Corretti oggi e da preservare: 0.5/19.99 come limiti float, '5.0' eq 5, NAN fallisce gt e lt
  ```
- **Dove:** `lib/Validations.php:338-404`.
  - Riga 360: `only_integer` vale solo per le stringhe.
  - Righe 371/382: cast a float.
  - Riga 384: il messaggio usa `(string)(float)`.
  - Riga 398: odd/even.
- **Soluzione (D5):**
  - Classificazione: INT (int PHP o stringa intera nel range), BIG (stringa intera fuori range, canonicalizzata), FLOAT (tutto il resto).
  - Confronti: INT×INT nativo; INT/BIG con un comparatore di stringhe di cifre; FLOAT con gli stessi operatori di oggi. Non si usa `<=>`, perché con NAN restituisce 1.
  - La parità di un BIG è la sua ultima cifra.
  - Nessuna dipendenza nuova; `Utils::is_odd()` resta invariato.

#### #57 — length conta byte

- **Esito:** VALIDA+. Il difetto taglia in due direzioni: `minimum` e `is` oggi accettano per conteggio di byte, quindi il fix li rende più severi sui multibyte.
- **Evidenza:**
  ```
  'héllo' maximum 5 → INVALID "too long" ; 'héllo' minimum 6 → VALID (sbagliato)
  MySQL/MariaDB utf8mb4 VARCHAR(5) e PG varchar(5): 5 caratteri ok, 6 rifiutati → limiti in CARATTERI
  VARBINARY(5): 'héllo' (6 byte) rifiutato ; TINYTEXT utf8mb4: limite 255 BYTE (128×é rifiutato)
  ```
- **Dove:** `lib/Validations.php:552` (`strlen`); la funzione è alle righe 490-569. I messaggi (712-714) dicono già "characters".
- **Soluzione (D7):** si conta in caratteri e si tengono i byte per `raw_type` binari.
  - Precedente: `Relationship.php:445-453` usa mbstring solo se presente e distingue le colonne binarie per `raw_type`.
  - Un UUID `BINARY(16)` validato con `'is' => 16` resta sicuro.

### 4.3 SQLite, tipi e introspezione

#### #66 — SQLite: `INT PRIMARY KEY` preso per rowid

- **Esito:** VALIDA+, più grave del riportato: con una pk **esplicita** il modello riceve il rowid, e i successivi `save()`/`delete()` colpiscono **un'altra riga**.
- **Evidenza:**
  ```
  tabella come `hosts` (id INT NOT NULL PRIMARY KEY):
    created Alice: model id='1' ; created Bob (id 42): model id='2'
    rename di Bob → ha modificato la riga di Alice ; $bob->delete() → ha cancellato Alice
  pk composta INTEGER (a,b)=(5,7): model a='1' ; rename + save() → true, riga invariata
  WITHOUT ROWID → rowid stantio ; INTEGER PRIMARY KEY DESC inline → non è alias (quirk)
  segnale affidabile: pragma index_list(t) con origin='pk' ⇔ NON è alias (12 forme verificate)
  ```
- **Dove:**
  - `lib/adapters/SqliteAdapter.php:119-122`: `in_array(strtoupper($type), ['INT','INTEGER']) && $c->pk`.
  - Il consumatore è `lib/Model.php:990-999`.
  - `test/SqliteAdapterTest.php:52-69` (gh-183, ereditato dall'upstream) passa e fissa il comportamento sbagliato.
- **Soluzione (D9):** override di `columns()` in `SqliteAdapter`. Costa un pragma in più per introspezione, ammortizzato dalla cache.
- **Monolite:** produzione MySQL non toccata. La sua suite di test SQLite ha pk composte INTEGER create via ORM (`fasce_eta_ricette`, `strutture_utenti`): dopo il fix ricevono i valori giusti.

#### #65 — SQLite: `tables()` elenca indici e trigger

- **Esito:** VALIDA. Contratto attuale per adapter:
  - MySQL: tabelle + viste;
  - MariaDB: anche le TEMPORARY;
  - PG: le tabelle base di tutti gli schemi, senza viste;
  - SQLite: tutto `sqlite_master`, compreso `sqlite_sequence`.
- **Dove:** `lib/adapters/SqliteAdapter.php:102-105`.
- **Fatti nuovi:**
  - Il filtro proposto dall'issue (`NOT LIKE 'sqlite_%'`) è sbagliato: `_` è un jolly di LIKE, quindi va scritto con escape.
  - `DatabaseLoader` incrocia l'elenco con le fixture, quindi i nomi in più sono innocui per i test.
  - Su MySQL `CREATE TRIGGER` fallisce in questo ambiente (1419, binlog).
- **Soluzione (D10):** `type IN ('table','view') AND name NOT LIKE 'sqlite\_%' ESCAPE '\'`, mantenendo l'alias `name`.

#### #67 — tabella mancante: PG/SQLite costruiscono un modello senza colonne

- **Esito:** VALIDA+.
- **Evidenza:**
  - Su PG e SQLite i sintomi sono `[]`, poi un warning in `Model.php:2290`, un `UndefinedPropertyException` fuorviante e "Inserting requires a hash".
  - Su PG `CREATE TABLE t ()` è legale e restituisce `[]`.
  - Un `[]` non viene mai trovato in cache (#62), quindi la tabella viene re-introspettata a ogni load.
- **Dove:** `lib/adapters/PgsqlAdapter.php:54-82`, `lib/adapters/SqliteAdapter.php:97-100`, `lib/Connection.php:313-323`, `lib/Table.php:630-642`.
- **Fatto chiave:** un'eccezione lanciata dentro la closure di `Cache::get` non viene messa in cache, quindi il controllo va nell'introspezione (adapter o `Connection::columns()`) e non dopo `Cache::get`.
- **Soluzione (D11):**
  - SQLite: zero righe → eccezione.
  - PG: zero righe → `SELECT to_regclass(?)`; NULL → eccezione, altrimenti `[]`. Questo disaccoppia #67 da #143.
- **Monolite:** produzione invariata. La sua suite SQLite crea solo un sottoinsieme di tabelle, quindi il maintainer deve eseguirla contro il ramo prima del rilascio.

#### #146 — DECIMAL come float

- **Esito:** VALIDA+. Si perdono cifre su **ogni** scrittura di un valore con 15 o più cifre significative (anche `create()` da stringa) e nei finder; vale su tutti gli adapter.
- **Evidenza:**
  ```
  ini precision=14 ; (string)1234567890123.45 = 1234567890123.4
  create('1234567890123.45') → salvato …123.40 ; 12345678901234567.89 → …235000.00
  find(['amount = ?', $m->amount]) / find_by_amount → NOT found (15 cifre)
  bind a 15 cifre: 0 errori di round-trip su 200.000 decimali ≤15 cifre (a 14: 12.050) ;
  0.1+0.2 continua a trovare 0.30 ; a 17/-1 le uguaglianze smettono di trovare la riga
  ```
- **Dove:** `lib/Column.php:151` (DECIMAL → `(float)`) e le righe 42-46 (anche `float`/`double` → DECIMAL); `lib/Connection.php:393-410` e `lib/adapters/SqliteAdapter.php:59-82` (bind).
- **Soluzione (D12):** un helper condiviso di formattazione dei float al bind. La lettura resta invariata.
- **Monolite:** nel dump tutte le colonne sono FLOAT (0 DECIMAL); dalle migration arrivano alcuni DECIMAL fino a (20,4); `php.ini` non imposta `precision`. Oggi non c'è perdita reale.
- **Test che fissano i float (restano verdi):** `ActiveRecordTest.php:489-493`, `ValidatesNumericalityOfTest.php:234-241`, `ColumnTest.php:94`.

### 4.4 Cache

Il monolite non configura nessuna cache ActiveRecord: l'unico riferimento è `Cache::flush()` in `upgrade_version.php:31`, che senza adapter non fa nulla.

#### #62 — i valori falsy non vengono mai trovati in cache

- **Esito:** VALIDA+ su memcache, redis e file, anche per `'0'` e `0.0`.
- **Evidenza:**
  ```
  false/0/''/[]/null/'0'/0.0 → la closure gira 3 volte su 3 get ; 'x' → 1 su 3
  presenza distinguibile SENZA cambiare formato: memcached getResultCode() 0/16 ;
  redis GET null (miss) / 'b:0;' (false) / 'N;' (null) ; file: envelope array_key_exists('value')
  ```
- **Dove:** `lib/Cache.php:91` (`if (!($value = static::$adapter->read($key)))`).
- **Contratti attuali di `read()`:**
  - in caso di miss restituiscono false (memcache) o null (redis/file), e test diversi fissano ciascun contratto;
  - gli adapter custom si possono agganciare solo assegnando `Cache::$adapter` (`CacheTestIsolationTest.php:30-34`).
- **Soluzione (D20):**
  - Un metodo pubblico di presenza sui 3 adapter inclusi; `read()` resta invariato.
  - `Cache::get` lo usa se c'è, altrimenti mantiene la truthiness di oggi.
  - Il PHPDoc di `Cache::$adapter` si allarga a `object`, altrimenti PHPStan vede il controllo di capacità come sempre vero (e la baseline è congelata).

#### #63 — `flush()` ignora il namespace

- **Esito:** VALIDA+.
  - Memcache svuota l'intero server e File l'intera directory, anche sotto un namespace.
  - Redis rispetta il namespace ma non fa l'escape dei caratteri glob: un flush sotto `v63_a*` ha cancellato `v63_ab`.
- **Dove:** `lib/cache/Memcache.php:22-38`, `lib/cache/File.php:29-41`, `lib/cache/Redis.php:73-87`. `Cache::initialize()` passa già le opzioni come secondo argomento, ma i costruttori non le ricevono.
- **Soluzione (D20):**
  - Il namespace arriva come secondo parametro opzionale del costruttore (i costruttori sono esenti dai controlli di compatibilità delle firme).
  - Memcache con namespace:
    - chiave di generazione `<ns>::__phpar_generation`, inizializzata con `add(random_int)` e incrementata con `increment` (verificato);
    - chiavi interne `<ns>::<gen>::<key>`; `read`/`write` traducono le chiavi `<ns>::…`;
    - le chiavi con namespace cambiano formato, quindi dopo l'upgrade c'è un miss.
  - File: si cancellano solo i file con prefisso `<ns>::` (`scandir` + `str_starts_with`).
  - Senza namespace il flush resta totale su tutti e tre gli adapter.
  - `Cache::$options` è pubblico e mutabile dopo `initialize()`: con un namespace cambiato a posteriori il flush resta totale, va documentato.

### 4.5 Postgres

Il monolite non usa Postgres tramite questa libreria: tutte le sue connessioni sono `mysql://`. Tutte le prove qui sotto danno lo stesso output su PG 18.6 e 15.19.

#### #92 — Postgres: Cat 1/4/5 e suite pgsql in CI

- **Esito:** VALIDA, invariata. La suite completa su pgsql dà **1588 test: 4 errori (Cat 1), 1 failure (Cat 4), 4 skip (Cat 5)**, nient'altro e 0 warning: il warning della baseline 2026-09 è sparito con #113.
- **Fatti nuovi:**
  - `PgsqlAdapterTest` (12 test propri + 98 ereditati da `AdapterTest`) e `PgsqlUpsertTest` (25) girano **già** su PG 15–18 in ogni step della CI, perché fissano la connessione. Solo la suite comportamentale non gira mai su pg.
  - `README.md:45` afferma che la CI esegue la suite completa anche su PG: oggi è falso.
  - **Cat 1** non riguarda solo Postgres:
    - le colonne con trattino o spazio (`rm-name`, `space out`) non si possono scrivere su **nessun** adapter (MySQL 1054, SQLite "no column named", PG 42703);
    - una pk mixed-case su PG rompe find/last/save/create.
  - **Cat 4:** dentro una transazione il 22P02 abortisce la transazione (25P02 alla query successiva), quindi intercettare l'errore e convertirlo non è sicuro.
  - **Cat 5:** su PG `update_all`/`delete_all` con `limit`/`order` toccano **tutte** le righe che soddisfano le condizioni. È documentato solo nei docblock; `AdapterTest:399` lo fissa già senza skip.
- **Dove** (Cat 1):
  - `lib/Connection.php:75` (`PDO::CASE_LOWER`), `lib/adapters/PgsqlAdapter.php:99`;
  - `lib/Table.php:455-464` (insert), `:585-599` (update), `:605-615` (delete), `:724-737` (`set_primary_key`);
  - `lib/Model.php:2124-2127` (ordine di default di `last`), `:2169-2196`, `:2287-2292`.
- **Soluzione (D13):** helper `@internal Table::column_name()`, applicato **dopo** `process_data()` così i valori legati restano identici; `SQLBuilder` invariato. Lo step CI:
  ```yaml
        - name: Tests (pgsql)
          run: PHPAR_CONNECTION=pgsql composer run test
  ```
  Costa circa +1 minuto per cella (54 s in locale).

#### #147 — PG: introspezione non limitata allo schema

- **Esito:** VALIDA+.
  - Si fondono le colonne di tabelle omonime in altri schemi e perfino quelle di un **indice** omonimo.
  - Ne nasce una pk composta fasulla (`["code","id"]`) con una sequence inventata, e `create()` va in errore.
- **Dove:** `lib/adapters/PgsqlAdapter.php:74` (`WHERE c.relname = ?`).
- **Soluzione (D16):** `WHERE c.oid = to_regclass(quote_ident(?))`. Verificato identico all'attuale su 28 tabelle single-schema.
- **Caveat preesistente:** `cache_identity` non considera un `SET search_path` eseguito a runtime.

#### #68 — PG: default estratti male

- **Esito:** VALIDA+.
  - `DEFAULT 0` diventa null, `DEFAULT ''` null, `DEFAULT NULL` la stringa `'NULL'`.
  - Le espressioni diventano testo SQL (`upper('x')`) o 0 (`(1+2)`).
  - I cast con cifre, maiuscole o array producono spazzatura (`Active'::"v68_Status"`).
  - L'espressione di una colonna generated viene letta come default.
- **Impatto:** su `NOT NULL DEFAULT 0` il `save()` di un nuovo modello fallisce (23502), mentre su MySQL funziona.
- **Dove:** `lib/adapters/PgsqlAdapter.php:68-72` (catena di `REGEXP_REPLACE`), `:124` (`if ($column['default'])`).
- **Soluzione (D15):** il parser usa le regole qui sotto, nell'ordine.
  1. null o colonna generated → nessun default.
  2. `^nextval\('((?:[^']|'')+)'::regclass\)$` → sequence (#47).
  3. `^'((?:[^']|'')*)'(::[^']+)?$` → letterale de-escapato, poi `Column::cast`.
  4. `^NULL(::[^']+)?$` → null.
  5. numero nudo o `true`/`false` → cast.
  6. qualsiasi altra cosa → nessun default.

  Si assume `standard_conforming_strings=on` (il default dalla 9.1) e lo si documenta.

#### #47 — PG: sequence e IDENTITY

- **Esito:** VALIDA+. `create()` fallisce in tutti questi casi:
  - tabella SERIAL rinominata (42P01);
  - IDENTITY ALWAYS (428C9);
  - apice nel nome (42601);
  - tabella mixed-case (42P01, per il case-folding);
  - default `nextval` su una sequence non owned (42P01).

  `Column::$sequence` viene introspettato ma mai letto (ed è codice morto); `next_sequence_value()` usa `\'`.
- **Dove:**
  - `lib/Table.php:757-769`;
  - `lib/adapters/PgsqlAdapter.php:28-36`, `:124-132`;
  - `lib/Column.php:108-112`;
  - `lib/Model.php:956-1007`;
  - `lib/SQLBuilder.php:242-256`, `:654-657`.
- **Soluzione (D14):** `pg_get_serial_sequence(c.oid::regclass::text, a.attname)`; il primo argomento va passato quotato, perché viene interpretato come identificatore. Per le tabelle SERIAL standard `Table::$sequence` e l'INSERT restano identici byte per byte.

#### #143 — PG: modelli con `$db`

- **Esito:** VALIDA. Un modello `$db`, o con `$table_name = '"s".t'` (la migrazione documentata da #129), introspetta 0 colonne:
  - `create()` va in eccezione;
  - `find`+`save` danno un warning più 42601;
  - le letture restituiscono tipi grezzi.
- **Premessa errata:** l'issue chiede "bytea come i modelli normali", ma i modelli normali leggono già `'Resource id #N'` (`Column.php:128`). La regressione su numeric è #146.
- **Dove:** `lib/Table.php:630-643` (alla riga 634 nomi non quotati per pg), `lib/adapters/PgsqlAdapter.php:74`.
- **Soluzione (D16):**
  - `to_regclass('"schema"."tabella"')` con le parti rese come `quote_name()` (`Connection::split_name_parts`).
  - `query_column_info($table)` resta a un argomento, con un helper privato o `@internal` per lo schema esplicito.
  - Chiave di cache coerente.
  - bytea letto con `stream_get_contents` per tutti i modelli PG.
- **Riferimento:** il codice rimosso da #129 (commit `3d558ae` e la parte F di `6f732b9`; PR head `6b64bc4`) contiene test riutilizzabili (`S26Book`, `S26MixTab`) ma anche le regressioni che la review aveva trovato. Va letto come traccia, non come soluzione.

### 4.6 Alias, eager load, serializzazione e #142

#### #144 — alias negli altri percorsi

- **Esito:** VALIDA+ su tutti gli adapter.
  - In count/exists/update_all/delete_all e nelle condizioni delle relazioni, un alias non mappato produce l'errore "unknown column". Lo stesso vale per lo `set` di `update_all`.
  - Con un alias omonimo di una colonna master è già incoerente: find, i finder dinamici e gli attributi usano l'alias, mentre count/exists/update_all/delete_all usano la colonna.
- **Perché la versione di #129 era insicura:** decideva in base allo schema **in cache**. Con una colonna aggiunta dopo il load (worker di lunga durata) o con una colonna generated su SQLite, una write passava da `WHERE status` a `WHERE state`.
- **Abort su PG:** un probe fallito dentro una transazione PG la abortisce (25P02), una lookup su `pg_attribute` no. MySQL e SQLite non abortiscono.
- **Dove:**
  - la mappatura c'è solo in `find()` (`Model.php:2153`) → `Table.php:254-256` / `map_names()` (655-676);
  - i percorsi non mappati sono `Model.php:1965-2027`, `:1090-1176` e `Relationship.php:618-627`.
- **Codice riutilizzabile:** gli helper rimossi da #129 (`5c89cbe` e la parte G di `6f732b9`, rimossi da `9bccabd`), e i modelli `MarqueeEvent`, `ShadowAliasVenue`, `ShadowAliasEvent`.
- **Monolite:** 22 alias, molti puntano a nomi di relazione; nessuno è omonimo di una colonna né usato in questi percorsi. Per i modelli soft-delete e con `$defaultConditions`, `RCModel::normalizzaArgsEIniettaDeletedAt()` riscrive gli hash in condizioni posizionali.

#### #137 — eager con `limit`/`offset`

- **Esito:** VALIDA. Esempio con 3 owner × 5 figli e `limit 2`:
  - si idratano 15 modelli e se ne tengono 6;
  - una include annidata sotto `limit 1` interroga 15 id invece di 3.

  I risultati sono corretti (eager = lazy).
- **Prototipo della finestra:** è uguale al lazy su MySQL 8.4/9.7, MariaDB 10.11/11.4, PG 15/18 e SQLite 3.46, per limit, offset, pk composte con NULL e i due tipi di `through`. Problemi trovati:
  - nomi di colonna duplicati nella derived table → errore 1060 su MySQL;
  - ordinali nell'`ORDER BY` della finestra → errore su MySQL, ordine sbagliato in silenzio altrove;
  - collation `ai_ci` → un owner riceve meno figli;
  - `ar_rn` finirebbe negli attributi se idratato con `find_by_sql`.
- **Dove:** `lib/Relationship.php:189-361` (riga 306: query senza LIMIT; righe 331-338: taglio per owner), `:409-429` (`eager_window`), `:443-475` (matcher di #133); `lib/Table.php:325-354`.
- **Monolite:** una sola has_many con limit (`Articoli.php:215-221`), caricata solo in lazy.

#### #124 — `to_csv` con `include`

- **Esito:** VALIDA+. "Array" e un warning anche con una has_many vuota e con `methods` che restituiscono array. Inoltre `@$this->options['only_header']` emette a ogni chiamata un warning soppresso, che gli error handler custom vedono comunque.
- **Dove:** `lib/Serialization.php:423-461` (`@` alla riga 425, `fputcsv` alla 457).
- **Monolite:** nessun `to_csv`.

#### #142 — validare le chiavi hash (FEATURE, won't-fix)

- **Esito:** ogni adapter rifiuta già una chiave sconosciuta con `DatabaseException` prima di toccare righe, su tutti i percorsi.
- **Perché un pre-controllo farebbe danni:** rifiuterebbe chiavi che funzionano oggi ma che l'introspezione non elenca:
  - MySQL: GIPK `my_row_id` con `show_gipk…=OFF`, `_rowid`;
  - MariaDB: `row_start`/`row_end` di `WITH SYSTEM VERSIONING`;
  - PG: `ctid`, `xmin`, `tableoid`;
  - SQLite: colonne generated, colonne nascoste FTS5, `rowid`;
  - qualsiasi colonna aggiunta dopo il caricamento del modello.
- **Soluzione (D17):** documentarlo nel README e fissare con test di guardia che queste chiavi continuano a funzionare.

### 4.7 Inflector, tooling e test

#### #48 — inflector: `Human` → `humen`

- **Esito:** PARZIALE. I casi riportati si riproducono, ma:
  - Rails si comporta **allo stesso modo, di proposito**: `irregular` costruisce `plural(/(m)an$/i, '\1en')` e le regole sono "frozen" (rails#14092, rails#17671);
  - il `\b` proposto dall'issue rompe `woman`, `fireman`, `SalesPerson` e 2 test (`InflectorTest.php:23`, `RelationshipTest.php:819`), perché `\b` considera `_` una lettera.
- **Dove:** `lib/Utils.php:393-402` (irregolari), `:429-435` e `:459-465` (cicli).
- **Soluzione (D23):** override dei plurali prima degli irregolari generici (human, german, roman, ottoman, shaman, talisman, caiman, cayman, doberman, walkman, mongoose) e una guardia `(?<![a-z])` sui singolari, che lascia invariati abdomen, acumen, albumen, bitumen, cerumen, dolmen, foramen, gravamen, hymen, lumen, omen, regimen, rumen, semen, specimen, stamen, yemen.
- **Monolite:** confronto su 795 nomi di classe e su tutti i nomi di associazione: **0 differenze**.

#### #125 — stub PHPStan per i consumer

- **Esito:** VALIDA+.
  - Contrariamente a quanto dice l'issue, `phpstan/extension-installer` registra **qualunque** tipo di pacchetto che abbia `extra.phpstan` (dalla 1.0.3).
  - Con l'installer, PHPStan dei consumer **va in abort** (exit 1), perché legge lo stub come NEON.
  - Senza stub ci sono 2 falsi positivi per dichiarazione di relazione.
  - Con `extension.neon` + `stubFiles`: 0 errori (verificato con un progetto consumer reale).
- **Monolite:** PHPStan livello 8, senza installer; 129 dichiarazioni di relazione nei path analizzati. Le PHPDoc autosufficienti sono tracciate in #156.

#### #16 — baseline PHPStan

- **Esito:** VALIDA. Tutte e 6 le voci (8 occorrenze) si eliminano **senza cambi a runtime**: senza baseline, `[OK] No errors` su PHP 8.3 e 8.5 e php-cs-fixer pulito (diff di prova `trialC-final.diff`).
- **Le voci:**
  - **Voce 2** non è codice morto: `Table::set_delegates()` aggiunge `'processed' => true`, quindi `is_delegated()` riceve davvero `true`. Un tipo nativo `array` romperebbe ogni modello: va corretta solo la PHPDoc e tolto il `&`.
  - **Voce 4:** PHPStan ignora `@property` su classi senza metodi magici. Si risolve restringendo `$this` con rami irraggiungibili che lanciano `RelationshipException`.
  - **Voci 1 e 5:** guardie vive coperte da test; si corregge solo la PHPDoc.
  - **Voce 6:** ramo morto, da rimuovere.

#### #148 — test instabile

- **Esito:** VALIDA+. È fallito in CI su PR #129 (`-'15:02:00' +'15:01:59'`) e lo riproduce un repro con tick forzato.
- **Test con la stessa race:** altri 8 test di `DateTimeTest` (righe 70, 81, 113, 119, 125, 131, 144, 150).
- **Soluzione:** un solo istante per classe di test.

## 5. Nuove issue aperte durante la verifica (#149–#156)

Le issue sono state aperte il 2026-10-06 con un repro verificato su `583fd8e` e sono tracciate in #91 (sezione "Found during the 2026-10-06 verification"). Il testo completo è su GitHub. Le decisioni BC sono **aperte**: il controller le chiede al maintainer all'inizio del task (D24).

| Issue | Difetto | Gravità | Decisione da prendere (raccomandazione) | Task |
|---|---|---|---|---|
| #149 | `find($pk, ['conditions' => …])` scarta la pk e restituisce **un altro record**; `count`/`exists($pk, conditions)` scartano le conditions | **alta** per chi la usa come scoping (bypass di autorizzazione); 0 chiamate nel monolite | AND di pk e conditions in tutti e tre (come Rails `where(c).find(id)`) | T29 |
| #150 | la condizione stringa `'0'` viene scartata → tutte le righe (finder e bulk) | media | solo `null`/`''`/`[]` significano "nessuna condizione"; `'0'` diventa una condizione vera (su PG da decidere) | T30 |
| #151 | regola plurale `ex$` senza gruppo: complex → complices, MatrixRow → matrices_row | bassa (nomi di tabella) | `/(matr|vert|ind)(?:ix|ex)$/i` come Rails; 0 nomi toccati nel monolite | T31 |
| #152 | colonne `REAL` tipizzate STRING su SQLite e PG | bassa | mappare `real` come `float` (cambio di tipo dell'attributo) | T32 |
| #153 | PG: una pk con default non-sequence (uuid) non si crea senza id esplicito | bassa | nessuna sequence, INSERT senza pk + `RETURNING` | T33 |
| #154 | `DatabaseException` perde SQLSTATE e la PDOException (code 0, nessun previous, stack trace nel messaggio) | bassa (DX) | `previous` + SQLSTATE accessibile; decidere sul testo del messaggio | T34 |
| #155 | uniqueness con pk composta esclude solo per la prima colonna | media | già deciso (D8): risolta nella PR di #123 | T9 |
| #156 | le PHPDoc delle relazioni non bastano a un consumer senza stub | bassa (tooling) | alias locali o `require` di `Relationship.php`, più `@phpstan-var` più ampie | T35 |

## 6. Fuori perimetro: blocchi di migrazione del monolite (D25)

Due modifiche già presenti nel fork, introdotte da #24 (`5d11684`), impediscono al monolite di passare da `zamzar/php-activerecord` v1.7.1 al fork. Entrambe sono verificate.

1. **`'constraints'` nelle relazioni.** Il monolite dichiara la chiave `'constraints' => […]` 30 volte in has_many/has_one, in 17 modelli; la legge `RCModel::getConstraints()` (`RCModel.php:437`). Il fork rifiuta le opzioni sconosciute (`lib/Relationship.php:537`): caricare uno di quei modelli lancia `RelationshipException: Unknown option 'constraints' for relationship …`.
2. **`public static array $belongs_to`.** 4 modelli ridichiarano la proprietà con tipo nativo:
   - `Lingue_abilitate_frontend.php:20`
   - `Licenze_contratti_logs.php:30`
   - `Rilevatori_presenze_canali.php:30`
   - `Centri_cottura_raggruppamento_ricette_piatti.php:29`

   Il fork dichiara `public static $belongs_to;` senza tipo (`lib/Model.php:280-284`), quindi PHP va in errore fatale: "Type of X::$belongs_to must not be defined (as in class ActiveRecord\Model)". Nessuna dichiarazione nella classe base può soddisfare sia i figli tipizzati sia quelli non tipizzati. Al contrario, `public static array $before_create` (`User.php`) non dà problemi, perché il fork non la dichiara.

**Decisione:** il fork non cambia. Il monolite sposta `'constraints'` fuori dalle dichiarazioni delle relazioni e toglie `array` nei 4 file. La guida di upgrade (T37) documenta questi due punti insieme a tutte le note BREAKING maturate dalla v1.7.1: release 2.0.0, 2.1.0, 2.2.0 e questo plan.

## 7. Note d'ambiente e di processo emerse

- **Runner:** con più processi in parallelo, la copia dell'albero a volte fallisce in modo transitorio con `cp: cannot open '/src/./.git/objects/…': Permission denied` (oggetti git scritti in contemporanea). Basta rilanciare. Escludere `.git` dalla copia in `phpar-test` lo eviterebbe.
- **Schema dei test:** `DatabaseLoader` ricrea lo schema **una volta per connessione per processo** (`test/helpers/DatabaseLoader.php:17-22`); per ogni test ricarica solo le righe delle fixture. Questo ha due conseguenze, e `CLAUDE.md` dice impropriamente "every test":
  - un test che altera una tabella di fixture inquina i test successivi, quindi le modifiche di schema vanno fatte su tabelle dedicate, create e droppate dentro il test;
  - una nuova tabella in `test/sql/*.sql` richiede un CSV in `test/fixtures/` (anche solo l'intestazione), perché `drop_tables()` droppa solo le tabelle che ne hanno uno.
- **Test PG già in CI:** `AdapterTest` gira dentro `MysqlAdapterTest`, `MariadbAdapterTest`, `PgsqlAdapterTest` e `SqliteAdapterTest` con connessione fissata. Un test PG messo lì è nel gate della CI già oggi, prima dello step pgsql di T22.
- **MySQL:** `CREATE TRIGGER` fallisce nell'ambiente Docker (1419, binlog), quindi niente trigger nei test MySQL. L'utente CI `phpar` non ha `SESSION_VARIABLES_ADMIN`, quindi il caso GIPK non è testabile.
- **CLAUDE.md:** oltre al punto "every test" sopra, dice che `ActiveRecord.php` fa `require` di ogni `lib/*.php`. In realtà `Validations`, `Serialization`, `Relationship`, `Expressions`, gli adapter e i backend di cache sono caricati in modo lazy. Da correggere a parte; tocca anche #156.
- **Immagini Docker:** durante la verifica sono state scaricate `mysql:8.4` e `postgres:15`, ancora nello store locale (`docker rmi` se non servono).
