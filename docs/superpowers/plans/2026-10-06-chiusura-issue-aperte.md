# Chiusura delle issue aperte (ottobre 2026) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** chiudere le issue aperte di `ristocloud-group/php-activerecord` verificate il 2026-10-06 (le 26 originali, più #92 e le nuove #149–#156) con una PR per task verso il branch ponte `integration/2026-10-issues`. A ogni ondata validata il ponte viene portato su `master`.

**Architecture:** 37 task in 9 ondate, ordinate per gravità e dipendenze. Ogni task corrisponde a una issue, nasce da `origin/integration/2026-10-issues`, si sviluppa in TDD in un worktree isolato e apre una PR con base il ponte. La PR passa da un reviewer indipendente e poi dal revisore umano. A fine ondata il controller apre la PR `wave/<n>` → `master` (merge commit), che chiude le issue dell'ondata.

**Tech Stack:** PHP 8.3–8.5, PHPUnit 12 con DSL snake_case, PHPStan livello 8 (baseline congelata fino a T36), php-cs-fixer PER-CS 3.0, Docker Compose (MySQL 9.7, MariaDB 11.4, Postgres 18, SQLite, memcached, redis), GitHub CLI.

**Spec:** `docs/superpowers/specs/2026-10-06-chiusura-issue-aperte-design.md`. È il report di verifica su `583fd8e` e contiene le decisioni D1–D25 del maintainer. Ogni task rimanda alla sua sezione: leggila prima di iniziare. Il testo della issue su GitHub (`gh issue view <N>`) descrive il problema; dove issue e spec divergono, vale la spec.

## Flusso dei branch

```
master  ─●──────────────────────────●(merge wave/1)──────────────●(merge wave/2) …
          \                         /                           /
ponte      ●─plan─●(PR fix/a)─●(PR fix/b)── … ──●(PR fix/c)─●(PR fix/d)
                   ↑ squash     ↑ squash          ↑            ↑
             un branch per task, creato da origin/integration/2026-10-issues
```

1. Il ponte `integration/2026-10-issues` nasce da `master` `583fd8e`. Il suo primo commit contiene questo plan e la spec.
2. Il branch di ogni task (nome indicato nel task) parte da `origin/integration/2026-10-issues` aggiornato. La PR ha `--base integration/2026-10-issues`; il revisore umano la approva e la mergia nel ponte con **Squash and merge**.
3. A fine ondata, con tutte le PR dell'ondata mergiate e validate, il controller crea `wave/<n>` dalla punta del ponte (`git push origin origin/integration/2026-10-issues:refs/heads/wave/<n>`) e apre la PR `wave/<n>` → `master`. Il maintainer la mergia con **Create a merge commit**, mai squash né rebase, altrimenti si perde l'ascendenza e l'ondata successiva ripresenterebbe tutti i commit.
   - Si passa da `wave/<n>` e non dal ponte perché il repo ha attivo "Automatically delete head branches". Una PR con head il ponte, una volta mergiata, cancellerebbe il ponte, e GitHub sposterebbe su `master` tutte le PR aperte verso di esso.
4. `Closes #N` chiude le issue solo verso il branch di default, quindi le PR verso il ponte non chiudono nulla. La PR d'ondata elenca un `Closes #N` per ogni issue dell'ondata. Dopo il merge il controller verifica che siano chiuse (altrimenti le chiude a mano citando la PR) ed esegue `.superpowers/bin/sync-tracker` per il tracker #91.
5. Se nel frattempo entra un hotfix su `master`, il controller mergia `origin/master` nel ponte con un merge commit. Mai rebase, mai force-push di un branch già pubblicato.

## Ruoli

- **Maintainer / revisore umano:** mergia le PR nel ponte e le PR d'ondata in `master`; decide ogni trade-off BC non previsto dalla spec e i gate dell'ondata 8.
- **Controller:** la sessione Claude Code principale, su questo Mac. Segue superpowers:subagent-driven-development: prepara il brief di ogni task da questo plan, lancia implementer e reviewer, verifica, fa push, apre le PR, tiene il ledger e sincronizza il tracker. **Si ferma dopo ogni blocco di 2–3 PR e fa rapporto al maintainer.**
- **Implementer** (sub-agente, un task alla volta): lavora solo nel proprio worktree, in TDD, e committa. Non fa push, non apre PR, non scrive su GitHub.
- **Reviewer** (sub-agente indipendente; opus, oppure sonnet per le re-review piccole): rivede il diff `BASE..HEAD` rispetto al task, alla spec e alle Global Constraints. Cerca regressioni old-vs-new e cambi BC non autorizzati.

## Global Constraints

Valgono per ogni task; il testo del task le dà per scontate.

- **Un task → un branch → una PR verso il ponte.** Worktree in `.superpowers/worktrees/<branch con / sostituito da ->`, branch creato da `origin/integration/2026-10-issues` dopo `git fetch origin`. Mai commit su `master` o sul ponte. Le dipendenze indicate nel task devono essere già mergiate nel ponte prima di creare il branch.
- **Backward compatibility = gate bloccante (CLAUDE.md).**
  - Il fix cambia comportamento SOLO sul percorso che il task nomina e SOLO come la decisione citata (D1–D25 nella spec §3) autorizza.
  - Restano invariati, salvo dove il task dice altrimenti: firme pubbliche, chiavi di opzione, array statici di configurazione, tipi di ritorno, tipi di eccezione, testi dei messaggi, default.
  - Non si aggiungono tipi nativi alle firme pubbliche esistenti: il monolite fa override non tipizzati di `count`, `exists`, `first`, `last`, `find`, `delete_all`, `update_all` e `reload`.
  - Se il fix corretto richiede un cambio osservabile non autorizzato, l'implementer si ferma e riporta NEEDS_CONTEXT con il trade-off, e il controller chiede al maintainer. Nessun agente decide una questione BC da solo.
- **MySQL è il target primario**; MariaDB usa `MysqlAdapter`; Postgres e SQLite devono continuare a funzionare. In un trade-off tra adapter vince MySQL, e il comportamento specifico di MySQL va in `MysqlAdapter`.
- **Modifiche minime** sul codice legacy: niente refactoring o riformattazione fuori dal perimetro del task (YAGNI). Nessuna nuova voce in `phpstan-baseline.neon`. Un nuovo file in `lib/` va aggiunto a mano in `ActiveRecord.php`.
- **PHP moderno (≥ 8.3) nel codice nuovo:** `[]`, dichiarazioni di tipo dove non alterano una firma pubblica, `??`/`?->`, `match` dove serve. API pubblica snake_case, stile PER-CS 3.0.
- **TDD e convenzioni dei test:**
  - Prima il test di regressione e la sua uscita RED, poi il fix, poi GREEN. La suite gira con `--fail-on-warning --fail-on-deprecation --fail-on-skipped --fail-on-risky`, quindi conta come RED anche un warning o una deprecation.
  - Le classi estendono `DatabaseTest`, con metodi `public function test_…()`, `set_up()`/`tear_down()` e assert snake_case (`assert_equals`, `assert_same`, `expect_exception`, …).
  - Nessun test può essere skippato nell'ambiente Docker. Ogni static modificato in un test va ripristinato, comprese le statiche di `Cache` (`Cache::$adapter`, `Cache::$options`, schema #139).
  - **Schema:** `DatabaseLoader` crea lo schema una volta per connessione per processo; per ogni test ricarica solo le righe delle fixture.
    - Una tabella nuova va in **tutti** i `test/sql/{mysql,pgsql,sqlite}.sql` **con un CSV** in `test/fixtures/`, anche solo l'intestazione.
    - In alternativa si crea e si droppa nel test stesso, con try/finally.
    - Mai alterare una tabella di fixture.
  - Un test messo in `test/helpers/AdapterTest.php` gira in tutte e quattro le classi `*AdapterTest`, ciascuna a connessione fissata, e quindi è già nel gate della CI anche su Postgres.
  - Su MySQL `CREATE TRIGGER` fallisce in questo ambiente (1419).
- **Eseguire i test.** Il container `tests` NON vede il codice dei worktree. Si usa sempre il runner `RUN=/Users/nicholasbuttura/Documents/Projects/php-activerecord/.superpowers/bin/phpar-test`, con `WT=<path assoluto del worktree>`:
  - `$RUN $WT <mysql|mariadb|sqlite|pgsql> [file di test | --filter nome]`: suite, o parte di suite, su quell'adapter;
  - `$RUN $WT gate`: mysql + mariadb + sqlite + analyse + cs, cioè la CI;
  - `$RUN $WT php < script.php` per gli script ad hoc, `$RUN $WT run examples/<dir>/<file>.php` per gli esempi.
  - Le esecuzioni sono serializzate da un lock, perché i DB di test sono condivisi.
  - PHP 8.5: prefissa `PHPAR_IMAGE=php-activerecord-tests:8.5`.
  - Se la copia fallisce con `cp: cannot open '/src/./.git/objects/…'` (git che scrive in parallelo), rilancia.
  - Se Docker non risponde ("getaddrinfo for mysql failed"): `open -a Docker`, poi `docker compose up -d` dalla root del repo.
- **Esempi:** ogni PR aggiorna `examples/` con una breve sezione numerata, nello stile del file, che mostra il comportamento garantito dal fix (risultato e, dove utile, `Model::table()->last_sql`).
  - Ogni esempio gira su SQLite senza server; una demo solo-Postgres va nella metà pgsql-guarded di `examples/sequences/`.
  - Se l'esempio aggiunge una capacità notevole, aggiorna la colonna "Demonstrates" di `examples/README.md` (e la tabella del `README.md` principale).
  - Il task dice esplicitamente quando l'esempio non si applica.
- **Commit:** in inglese, convenzionali (`fix:`/`test:`/`docs:`/`ci:`/`perf:`/`chore:`). Corpo: causa, cosa cambia, come è testato. Ultima riga `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Mai** rebase o force-push di un branch pubblicato: i conflitti si risolvono mergiando `origin/integration/2026-10-issues` nel branch. I follow-up su una PR aperta sono nuovi commit sulla stessa PR.
- **Il monolite** (`~/Documents/Projects/ristocloud`) si usa SOLO in lettura (grep), per verificare l'impatto reale; non si esegue né si modifica.

## Review Focus

Cinque classi di input che i test dei singoli task rischiano di non esercitare. Ogni riga ha un test nel task indicato.

1. **Collation MySQL/MariaDB case- e accent-insensitive.** Per il database `'abc' = 'ABC'` e `'e' = 'é'`, mentre il confronto in PHP le distingue. Test in T23 (una chiave che differisce da una colonna solo per maiuscole non viene rimappata) e in T24 (le chiavi testuali su MySQL restano sul percorso di oggi).
2. **Schema in cache vecchio nei processi long-running.** Una colonna o tabella creata dopo il caricamento del modello. Test in T23 (colonna aggiunta dopo il load), T13 (una tabella mancante non lascia voci in cache) e T15 (un `[]` di una tabella mancante non viene mai memorizzato).
3. **PHP 8.5.** Warning e deprecation su offset null e conversioni float→int. Tutti i test nuovi girano anche su 8.5. Test dedicati in T10 (odd/even oltre l'int range senza warning) e T9 (`reload()` senza deprecation di offset null).
4. **Transazioni Postgres.** Qualunque statement fallito abortisce la transazione (25P02), quindi un "prova e intercetta" non è mai sicuro su PG. Test in T23 (alias mappato dentro `Model::transaction()`) e T13 (la verifica con `to_regclass` non lancia mai).
5. **Interi oltre `PHP_INT_MAX` e precisione dei float**, che dopo #131 arrivano come stringhe esatte. Test in T6 (pk unsigned oltre `PHP_INT_MAX` resta stringa), T10 (confronti esatti) e T12 (DECIMAL a 15 cifre).

## Ciclo TDD standard

Ogni task lo segue. Lo step "Test RED" del task dice cosa scrivere; gli altri step dicono solo ciò che è specifico del task.

1. Scrivi i test del task.
2. **RED:** `$RUN $WT <adapter> <file di test>` per ogni adapter elencato nel task. I test nuovi falliscono per il motivo indicato (failure, error, warning o deprecation). Incolla l'output nel report.
3. Implementa come indica il task.
4. **GREEN:** stessi comandi → OK.
5. **Gate:**
   - `$RUN $WT gate` → `GATE: PASS`.
   - Se il task tocca Postgres, `Table`, `Model` o un adapter: `$RUN $WT pgsql` → nessun rosso oltre la baseline qui sotto.
   - Test nuovi su PHP 8.5: `PHPAR_IMAGE=php-activerecord-tests:8.5 $RUN $WT mysql <file di test>` → OK.
6. **Esempio:** `$RUN $WT run examples/<dir>/<file>.php` → l'output atteso dal task; incollalo nel report.
7. **Commit** con il messaggio del task.

## Baseline (master `583fd8e`)

- CI verde: gate mysql/mariadb/sqlite + PHPStan + cs.
- Suite pgsql completa: **1588 test, 4 errori, 1 failure, 4 skip**, esattamente gli elementi di #92:
  - Cat 1 (errori): `ActiveRecordTest::test_flag_dirty`, `ActiveRecordWriteTest::test_set_date_flags_dirty`, `ActiveRecordWriteTest::test_set_date_flags_dirty_with_php_datetime`, `DateFormatTest::test_datefield_gets_converted_to_ar_datetime`.
  - Cat 4 (failure): `ActiveRecordFindTest::test_find_nothing_with_sql_in_string`.
  - Cat 5 (skip): `ActiveRecordWriteTest::test_delete_all_with_limit_and_order`, `…::test_update_all_with_limit_and_order`, `SQLBuilderTest::test_update_with_limit_and_order`, `…::test_delete_with_limit_and_order`.
- Dopo T16 restano solo i 4 errori di Cat 1; dopo T21 la suite pgsql è verde e T22 la porta in CI.

## Protocollo del controller per ogni task

- [ ] 1. `git fetch origin`; controlla che le dipendenze del task siano mergiate nel ponte; ri-verifica sulla punta del ponte che il repro del task sia ancora rosso. Se non lo è più, fermati e riporta.
- [ ] 2. Per i task dell'ondata 8: chiedi il gate BC al maintainer (AskUserQuestion, opzione raccomandata per prima) e registra la risposta.
- [ ] 3. `gh issue edit <N> --add-assignee @me`; `git worktree add .superpowers/worktrees/<dir> -b <branch> origin/integration/2026-10-issues`; annota la BASE (sha) nel ledger.
- [ ] 4. Brief all'implementer: Global Constraints, Ciclo TDD standard, testo integrale del task, sezione della spec citata, path del worktree.
- [ ] 5. Report dell'implementer (DONE / DONE_WITH_CONCERNS / NEEDS_CONTEXT / BLOCKED). Su NEEDS_CONTEXT con un trade-off BC: domanda al maintainer e decisione annotata nel ledger e nel corpo della PR.
- [ ] 6. Reviewer indipendente sul diff `BASE..HEAD`, poi fix round finché non approva.
- [ ] 7. Verifica del controller:
  - i test nuovi sono ROSSI sul codice di BASE;
  - `gate` PASS;
  - pgsql senza rossi nuovi rispetto alla baseline;
  - test nuovi verdi su PHP 8.5;
  - esempio eseguito.
- [ ] 8. `git push -u origin <branch>`; `gh pr create --base integration/2026-10-issues --title "<titolo del commit>" --body-file <file>` con il template; `gh pr edit <PR> --add-reviewer nicolas-ristocloud`.
- [ ] 9. Difetti nuovi trovati strada facendo: nuova issue + riga in #91; non allargare la PR.
- [ ] 10. Dopo il merge nel ponte: rimuovi worktree e branch locale, aggiorna il ledger, controlla che le altre PR aperte non siano entrate in conflitto.
- [ ] 11. Dopo ogni blocco di 2–3 PR: STOP e rapporto al maintainer.

Ledger (gitignored): `.superpowers/sdd/issue-fixes-2026-10/progress.md`, una riga per evento (avvio, report, review, verifica, push, merge, decisione).

## Template della PR (verso il ponte)

```markdown
> Target: `integration/2026-10-issues` (wave <n>). Closes #<N> when the wave reaches `master`.

## Problem
<repro on the bridge tip with the real output>

## Fix
<what changes and where; why this design (decision D-x of the spec)>

## Verification
- <new tests: RED on the bridge → GREEN; adapters covered>
- Gate: mysql, mariadb, sqlite OK (<n> tests), PHPStan level 8 OK (baseline unchanged), php-cs-fixer clean; pgsql: <no new failures vs baseline>; PHP 8.5: <OK>.

## Examples
<file and section>

## Backward compatibility — please weigh before merging
<every observable change, who is affected, maintainer decision applied (D-x)>

## Release note
<one line for the release draft: **BREAKING** / Fixes / Features / Tests>

Closes #<N>.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

## Chiusura di un'ondata

- [ ] 1. Tutte le PR dell'ondata sono mergiate nel ponte. `git fetch origin`; in un worktree dedicato alla punta del ponte: `gate`, `pgsql` rispetto alla baseline corrente, test nuovi dell'ondata su PHP 8.5.
- [ ] 2. `git push origin origin/integration/2026-10-issues:refs/heads/wave/<n>`, poi `gh pr create --base master --head wave/<n>`. Il corpo contiene: l'elenco delle PR dell'ondata, le sezioni BC aggregate, le righe di release note, un `Closes #N` per issue.
- [ ] 3. Il maintainer mergia con **Create a merge commit**.
- [ ] 4. Il controller verifica che ogni issue dell'ondata sia chiusa (altrimenti la chiude a mano citando la PR), esegue `.superpowers/bin/sync-tracker` e annota nel ledger.

## Mappa delle ondate

| Ondata | Task (issue) | Dipendenze interne | In parallelo |
|---|---|---|---|
| 1 — Integrità dei dati, CI stabile | T1 (#145), T2 (#66), T3 (#148) | — | tutti |
| 2 — Finder e chiavi primarie | T4 (#33), T5 (#38), T6 (#55), T7 (#65) | T7 dopo T2 | T4 ∥ T5 ∥ T6 (stesso file, zone diverse) |
| 3 — Validazioni | T8 (#51), T9 (#123 + #155), T10 (#49), T11 (#57) | T9 dopo T8 | T8 ∥ T10 ∥ T11 |
| 4 — Tipi, introspezione, cache | T12 (#146), T13 (#67), T14 (#63), T15 (#62) | T12 dopo T7; T13 dopo T2; T15 dopo T13 e T14 | T12 ∥ T13 ∥ T14 |
| 5 — Postgres | T16 (#92 Cat 4/5), T17 (#147), T18 (#68), T19 (#47), T20 (#143), T21 (#92 Cat 1), T22 (#92 CI) | T17 dopo T13; T18 dopo T17; T19 dopo T18 e T6; T20 dopo T19; T21 dopo T19 e T5; T22 dopo T16–T21 | T16 ∥ T17; T20 ∥ T21 |
| 6 — Alias, eager, serializzazione | T23 (#144), T24 (#137), T25 (#124), T26 (#142) | T23 dopo T1, T4, T21; T24 dopo T5; T26 dopo T23 | T23 ∥ T24 ∥ T25 |
| 7 — Inflector e tooling | T27 (#48), T28 (#125) | T28 dopo T22 | T27 ∥ T28 |
| 8 — Nuove issue (gate BC) | T29 (#149), T30 (#150), T31 (#151), T32 (#152), T33 (#153), T34 (#154), T35 (#156) | T29 dopo T4, T5, T23; T30 dopo T1; T31 dopo T27; T33 dopo T19; T35 dopo T28 | dove i file non si sovrappongono |
| 9 — Chiusura | T36 (#16), T37 (guida di upgrade) | T36 dopo T1–T35; T37 dopo T1–T36 | — |

**File caldi** (conflitti attesi, solo meccanici se si rispetta l'ordine):

| File | Task |
|---|---|
| `lib/Model.php` | T1, T4, T5, T6, T9, T19, T21, T23, T29, T36 |
| `lib/Validations.php` | T8, T9, T10, T11, T36 |
| `lib/adapters/SqliteAdapter.php` | T2, T7, T12, T13, T24 |
| `lib/adapters/PgsqlAdapter.php` | T13, T17, T18, T19, T20, T23, T33 |
| `lib/Table.php` | T19, T20, T21, T23, T24, T33 |
| `lib/Connection.php` | T6 (solo docblock), T12, T13, T23, T24, T34 |
| `lib/Relationship.php` | T5, T23, T24, T36 |
| `lib/cache/*` | T14, T15 |
| `README.md` | T1, T22, T23, T26, T28, T37 |
| `examples/finders/finders.php` | T1, T4, T5 (punti di inserimento distinti) |
| `examples/validations/validations.php` | T8, T10, T11 (sezioni in coda) |

---

## Ondata 1 — Integrità dei dati e CI stabile

### Task 1 — #145: `delete_all()`/`update_all()` non agiscono più su tutte le righe per un argomento malformato

**Issue:** #145 · **Branch:** `fix/145-bulk-write-options` · **Decisione:** D1 · **Dipende da:** — · **Spec:** §4.1

**Files:**
- Modify: `lib/Model.php`:
  - `delete_all()` (1090-1115) e `update_all()` (1146-1176), con i loro docblock (1056-1145);
  - nuovo helper privato subito dopo `update_all()`.
- Modify: `README.md`, paragrafo "Hash conditions" (~236-246): le forme accettate dai bulk.
- Create: `test/BulkWriteOptionsTest.php`.
- Example: `examples/finders/finders.php` (nuova sezione dopo la riga `update_all(set restocked_at, stale)`, ~76), più la cella `finders/` in `examples/README.md`.

**Interfaces:**
- Produce `private static function bulk_write_options(mixed $options, string $method): array`, dove `$method` vale `'delete_all'` o `'update_all'` e serve nei messaggi.
  - Restituisce l'hash di opzioni normalizzato: chiave `conditions` quando c'è una condizione; per `update_all` sempre anche `set`.
  - Altrimenti lancia `ActiveRecordException` prima di qualsiasi SQL.
  - È consumato da T23 (mappa gli alias su `conditions`/`set`) e T30.

**Comportamento richiesto.** Le "chiavi di opzione" sono quelle di `Model::$VALID_OPTIONS`, più `set` per `update_all`.

| `delete_all($options)` | Oggi | Dopo |
|---|---|---|
| assente, `null`, `[]` | tutte le righe | invariato |
| stringa | frammento WHERE | invariato |
| hash con sole chiavi di opzione | usa `conditions`/`limit`/`order`, ignora le altre | invariato, SQL byte-identico |
| hash senza chiavi di opzione (hash nudo) | tutte le righe | `['conditions' => $options]` → `DELETE … WHERE …` |
| hash con chiavi di opzione **e** altre chiavi | `LIMIT` su righe arbitrarie / tutte su PG | `ActiveRecordException("Unknown key(s): <altre chiavi>")` |
| lista non vuota (`['name = ?', 'a']`) | tutte le righe | `ActiveRecordException("Invalid options for delete_all(): pass positional conditions as ['conditions' => [...]]")` |
| int, float, bool | tutte le righe (su PG un valore ≠ 0 dà errore 42804) | `ActiveRecordException("Invalid options for delete_all(): expected an options hash or a conditions string")` |

| `update_all($options)` | Oggi | Dopo |
|---|---|---|
| hash con `set` e chiavi di opzione | come oggi | invariato |
| hash con `set` e altre chiavi (colonne, refusi) | aggiorna tutte le righe | `ActiveRecordException("Unknown key(s): …")` |
| hash senza `set` | warning PHP + `ActiveRecordException('Updating requires a hash or string.')` | stessa eccezione e stesso messaggio, **senza** warning |
| stringa o scalare | `TypeError`, oppure warning + eccezione | `ActiveRecordException('Updating requires a hash or string.')` |

Le opzioni dei finder passate ai bulk (`select`, `joins`, `from`, `offset`, `include`, `readonly`, `group`, `having`) restano ignorate. La condizione stringa `'0'` resta com'è (#150, T30).

- [ ] **Step 1 — Test RED** in `test/BulkWriteOptionsTest.php` (`extends DatabaseTest`). Fixture `authors`: 4 righe, `parent_author_id` 3,2,1,2, nomi `Tito`, `George W. Bush`, `Bill Clinton`, `Uncle Bob`.

  | Metodo | Chiamata | Atteso |
  |---|---|---|
  | `test_delete_all_bare_hash_is_conditions` | `Author::delete_all(['parent_author_id' => 2])` | restituisce 2; `Author::count()` = 2; `Author::table()->last_sql` contiene `WHERE` |
  | `test_delete_all_positional_list_throws` | `Author::delete_all(['name = ?', 'Tito'])` | `ActiveRecordException`; `count()` = 4 |
  | `test_delete_all_mixed_hash_throws` | `delete_all(['parent_author_id' => 2, 'limit' => 1])` | `ActiveRecordException`, messaggio con `Unknown key(s): parent_author_id`; `count()` = 4 |
  | `test_delete_all_conditions_plus_unknown_key_throws` | `delete_all(['conditions' => ['name' => 'Tito'], 'name' => 'x'])` | `ActiveRecordException` (`Unknown key(s): name`); 4 |
  | `test_delete_all_misspelled_conditions_key_reaches_the_database` | `delete_all(['condition' => ['name' => 'Tito']])` | `DatabaseException` (colonna sconosciuta); `count()` = 4 |
  | `test_delete_all_scalar_throws` | `delete_all(2)`, `delete_all(0)`, `delete_all(true)` | `ActiveRecordException` per ciascuno; 4 |
  | `test_update_all_extra_key_beside_set_throws` | `update_all(['set' => ['name' => 'X'], 'name' => 'Tito'])` e la variante con `'condition'` | `ActiveRecordException`; `Author::count(['conditions' => ['name' => 'X']])` = 0 |
  | `test_update_all_without_set_throws_without_warning` | `update_all(['name' => 'X'])` | `ActiveRecordException('Updating requires a hash or string.')`; nessun warning |
  | `test_update_all_string_throws` | `update_all("name = 'X'")` | `ActiveRecordException` (oggi `TypeError`) |

  Guardie (verdi già su master):
  - `delete_all()` = 4; `delete_all([])` = 4; `delete_all("name = 'Tito'")` = 1; `delete_all(['conditions' => ['name' => 'Tito']])` = 1;
  - `update_all(['set' => ['name' => 'Y']])` = 4.
  - Restano verdi anche `test/ActiveRecordWriteTest.php:386-474`, `test/helpers/AdapterTest.php:399-420` (`test_gh34_update_all_and_delete_all_with_limit_zero`) e `test/DateBindValuesTest.php`.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`: i test con hash nudo, lista, hash misto e scalare falliscono perché oggi cancellano 4 righe (su PG `delete_all(2)` fallisce per la classe dell'eccezione); `update_all` senza `set` fallisce per il warning.
- [ ] **Step 3 — Implementazione:**
  - `delete_all()` e `update_all()` chiamano `bulk_write_options()` come prima istruzione, poi proseguono con il codice di oggi sull'hash normalizzato.
  - Nessuna firma pubblica cambia, nessun tipo nativo aggiunto.
  - Docblock: chiudi la stringa rotta di `delete_all` (riga ~1076), usa `[]`, documenta l'hash nudo e le eccezioni.
- [ ] **Step 4 — Esempio** (`examples/finders/finders.php`): dentro `Widget::transaction(function () { …; return false; })` stampa
  - `count(['category' => 'gizmos'])` e `delete_all(['category' => 'gizmos'])`, con l'SQL (un `WHERE` sulla colonna `category`), che devono coincidere;
  - in un `try`, il messaggio di `update_all(['set' => ['in_stock' => 0], 'category' => 'gizmos'])`.
- [ ] **Step 5 — Commit:** `fix: treat a bare hash as conditions in delete_all() and reject ambiguous bulk-write options (#145)`

**PR — Backward compatibility:**
- Cambiano solo forme che oggi cancellano o aggiornano righe non volute: hash nudo → filtrato; lista, hash misto, refuso accanto a `set` e scalare → `ActiveRecordException` prima di qualsiasi SQL.
- `update_all` senza `set` non emette più il warning; con una stringa lancia `ActiveRecordException` invece di `TypeError`.
- Le forme documentate producono SQL identico.
- Monolite: 0 chiamate nelle forme che cambiano (293 `delete_all`, 153 `update_all` classificati).

**Release note:** `**BREAKING (behavior):** delete_all()/update_all() no longer act on every row for a malformed argument: a bare hash is used as conditions (like count()/exists()), positional lists, mixed hashes and scalars throw ActiveRecordException before any SQL (#145)`

### Task 2 — #66: SQLite segna `auto_increment` solo sul vero alias del rowid

**Issue:** #66 · **Branch:** `fix/66-sqlite-rowid-alias` · **Decisione:** D9 · **Dipende da:** — · **Spec:** §4.3

**Files:**
- Modify: `lib/adapters/SqliteAdapter.php`:
  - `create_column()` (112-153; condizione alle righe 119-122);
  - nuovo override di `columns($table)`, stessa firma di `Connection::columns()`, che legge `pragma index_list`.
- Modify: `test/SqliteAdapterTest.php`: ribalta `test_gh183_sqliteadapter_autoincrement` (52-69) e aggiungi il test delle forme.
- Modify: `test/PrimaryKeyWriteGuardTest.php`, che usa già `news_read_receipts`: casi cross-adapter.
- Example: `examples/sequences/sequences.php` e `sequences.sql` (metà SQLite), più la riga `sequences/` in `examples/README.md`.

**Interfaces:**
- Produce: su SQLite, `Column::$auto_increment === true` solo per l'alias del rowid. Il consumatore `Model::insert()` (990-999) resta invariato.
- Il nuovo `columns()` è il punto in cui T13 aggiungerà il controllo "tabella mancante": chiama `parent::columns()` per primo.

**Comportamento richiesto:**
- `auto_increment` è true solo se valgono insieme queste tre condizioni:
  - la pk è una sola colonna;
  - il tipo dichiarato è esattamente `INTEGER` (senza distinzione di maiuscole);
  - `pragma index_list(<tabella>)` non ha righe con `origin = 'pk'`.
- Con `auto_increment` false e la pk fornita, il modello conserva il valore dato. Se la pk è omessa resta `null`, e un successivo `save()` o `delete()` è rifiutato dalla guardia di #41 con il messaggio esistente.
- MySQL, MariaDB e PG restano invariati.

- [ ] **Step 1 — Test RED.**
  - `SqliteAdapterTest::test_gh183_sqliteadapter_autoincrement`: ora `assert_false($this->connection->columns('hosts')['id']->auto_increment)` (`hosts` è `id INT NOT NULL PRIMARY KEY`).
  - `SqliteAdapterTest::test_auto_increment_only_for_the_rowid_alias`: per ogni forma crea la tabella, assert su `columns(<t>)[<pk>]->auto_increment`, poi DROP in `finally`.

    | DDL | Atteso |
    |---|---|
    | `(id INTEGER PRIMARY KEY, n TEXT)` | true |
    | `(id integer not null primary key, n TEXT)` | true |
    | `(id INTEGER PRIMARY KEY AUTOINCREMENT, n TEXT)` | true |
    | `(id INTEGER, n TEXT, PRIMARY KEY(id DESC))` | true |
    | `(id INT PRIMARY KEY, n TEXT)` | false |
    | `(id INTEGER PRIMARY KEY DESC, n TEXT)` | false |
    | `(id INTEGER PRIMARY KEY, n TEXT) WITHOUT ROWID` | false |
    | `(a INTEGER, b INTEGER, n TEXT, PRIMARY KEY(a, b))` (pk `a`) | false |
    | `(id BIGINT PRIMARY KEY, n TEXT)` | false |
  - `PrimaryKeyWriteGuardTest::test_explicit_pk_is_kept_after_create` (tutti gli adapter):
    - `$h = Host::create(['id' => 42, 'name' => 'x']); assert_equals(42, $h->id);`
    - `$h->name = 'y'; $h->save(); assert_equals('y', Host::find(42)->name);` e nessun'altra riga cambia.
  - `PrimaryKeyWriteGuardTest::test_composite_integer_pk_is_kept_after_create`: con il modello inline su `news_read_receipts` già usato nel file, `create(['user_id' => 9, 'story_id' => 9])->user_id` vale 9.
  - Usa `assert_equals`, non `assert_same`, così il test resta indipendente da T6.
- [ ] **Step 2 — RED** su `sqlite`: falliscono gh183, le forme false e i due test cross-adapter (oggi il modello riceve il rowid). `mysql` e `pgsql`: i test cross-adapter sono già verdi; non devono essere skippati.
- [ ] **Step 3 — Implementazione:**
  - In `create_column()` restringi il tipo a `INTEGER`.
  - In `columns()` chiama `parent::columns($table)`, poi rimetti `auto_increment = false` se:
    - le colonne di pk sono più di una; oppure
    - `pragma index_list(<tabella quotata>)` ha una riga con `origin = 'pk'`.
  - Le colonne restano in cache come oggi: costa un pragma in più per ogni introspezione.
- [ ] **Step 4 — Esempio** (`examples/sequences/`, metà SQLite): una tabella `INT PRIMARY KEY` creata con `id` esplicito conserva l'id; una tabella `INTEGER PRIMARY KEY` riceve il rowid.
- [ ] **Step 5 — Commit:** `fix: flag auto_increment only for SQLite's rowid alias so an explicit pk is kept (#66)`

**PR — Backward compatibility** (solo SQLite):
- `Column::$auto_increment` diventa false per `INT`, per le pk composte, per `WITHOUT ROWID` e per `PRIMARY KEY DESC` inline.
- Dopo `create()` una pk esplicita resta quella data, mentre oggi diventa il rowid e update/delete colpiscono un'altra riga.
- Una pk non auto-increment omessa resta `null`, e update/delete vengono rifiutati.
- Le colonne già in cache conservano il vecchio flag fino alla scadenza.
- Monolite: produzione MySQL non toccata; la sua suite SQLite con pk composte INTEGER riceve i valori corretti.

**Release note:** `Fixed SQLite treating any INT/INTEGER primary key as the rowid alias: create() with an explicit id on an INT PRIMARY KEY (or a composite/WITHOUT ROWID pk) received the internal rowid, so later save()/delete() hit another row (#66)`

### Task 3 — #148: `DateTimeTest` confronta un solo istante

**Issue:** #148 · **Branch:** `test/148-datetime-single-instant` · **Decisione:** — (solo test) · **Dipende da:** — · **Spec:** §4.7

**Files:**
- Modify: `test/DateTimeTest.php` (nessun file in `lib/`).

**Comportamento richiesto:**
- Ogni aspettativa della classe usa lo stesso istante di `$this->date` (`date($format, $this->date->getTimestamp())`), oppure un istante fisso (`new DateTime('2010-01-02 03:04:05')`) con aspettative letterali.
- `test_set_iso_date` e `test_set_time` costruiscono entrambi gli oggetti dalla stessa stringa fissa.

- [ ] **Step 1 — Modifica** i 9 test con la race:
  - `test_change_default_format_to_format_string` (:138);
  - `test_format_by_friendly_name` (:113);
  - `test_format_by_custom_format` (:119);
  - `test_format_uses_default` (:125);
  - `test_all_formats` (:131);
  - `test_change_default_format_to_friently` (:144);
  - `test_to_string` (:150);
  - `test_set_iso_date` (:70);
  - `test_set_time` (:81).
- [ ] **Step 2 — Dimostrazione** (il test è flaky, quindi non c'è un RED deterministico): uno script ad hoc (`$RUN $WT php`) replica il test con `time_sleep_until()` al secondo successivo tra `set_up()` e l'asserzione. La versione attuale fallisce, la nuova no (spec §4.7).
- [ ] **Step 3 — GREEN:** `DateTimeTest` su `mysql`, `sqlite` e su PHP 8.5. `grep -n "date(" test/DateTimeTest.php`: nessuna `date()` senza timestamp.
- [ ] **Step 4 — Esempio:** non si applica (solo test).
- [ ] **Step 5 — Commit:** `test: compare DateTimeTest expectations against a single instant (#148)`

**PR — Backward compatibility:** nessuna (solo test). **Release note:** `Tests: DateTimeTest no longer compares two clock readings (flaky CI) (#148)`

---

## Ondata 2 — Finder e chiavi primarie

### Task 4 — #33: `exists()`/`count()` interpretano le pk come `find()`

**Issue:** #33 · **Branch:** `fix/33-count-exists-pk-args` · **Decisione:** D2 · **Dipende da:** — · **Spec:** §4.1

**Files:**
- Modify: `lib/Model.php`:
  - `finder_conditions_from_args()` (1965-1978, privata; firma invariata);
  - docblock di `count()`/`exists()` (1980-2015);
  - se serve, un helper privato condiviso con `find_by_pk()` per `date_pk_finder_value()` (2169-2175).
- Create: `test/CountExistsPkArgumentsTest.php`.
- Example: `examples/finders/finders.php`, dopo la sezione `count(limit 0)` (~54).

**Interfaces:**
- Produce: `private static function finder_conditions_from_args(array $args): array` con la stessa firma e le stesse opzioni in uscita, più le condizioni di pk corrette. Consumata da T23 e T29.

**Comportamento richiesto:**

| Chiamata | Oggi | Dopo |
|---|---|---|
| `count()`, `count(null)`, `count([])` (idem `exists`) | tutte le righe | invariato (gh149) |
| `count(['conditions' => …])`, hash nudo | come oggi | invariato |
| `count(0)`, `count('0')`, `count('')`, `count(false)` | tutte le righe | `WHERE pk = <valore>`, come `find()` |
| `exists(0)`, `exists('0')` | true | false, salvo una riga con quella pk |
| `count(1, 2, 3)` | 1 | `WHERE pk IN(?,?,?)` → 3 |
| `exists(999, 1)` | false | true |
| pk DATE: `DatedCount::count(new DateTime('2026-01-02'))` | 0 su SQLite | formattata da `date_pk_finder_value()`: trova le righe come `find()` |
| pk + conditions: `count(1, ['name' => 'b'])` | conditions scartate | **invariato** (#149, T29) |

Su PG, con pk intera, `count('')` lancia `DatabaseException` 22P02 come `find('')`; è accettato da D2.

- [ ] **Step 1 — Test RED** in `test/CountExistsPkArgumentsTest.php` (fixture `authors` 1–4, nessun `author_id` 0):
  - `test_falsy_pk_is_a_pk_value`: `Author::count(0)` = 0, `count('0')` = 0, `exists(0)` false, `exists('0')` false.
  - `test_several_pks_are_an_in_list`: `count(1, 2, 3)` = 3, `exists(999, 1)` true, `exists(998, 999)` false.
  - `test_blank_values_on_a_string_pk`: classe locale `AuthorByName extends ActiveRecord\Model { static $table_name = 'authors'; static $primary_key = 'name'; }`. Attesi: `exists('')` false, `count('')` 0, `count('0')` 0, `count('Tito')` 1.
  - `test_date_pk_is_formatted_like_find`: `DatedCount::count(new DateTime(<giorno della fixture dated_counts>))` è uguale a `count(find('all', ['conditions' => ['day' => …]]))`. Ricava la data dalla fixture `test/fixtures/dated_counts.csv`.
  - Guardia `test_no_argument_null_and_empty_array_count_everything`: `count()` = `count(null)` = `count([])` = 4.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`: i casi falsy e multi-pk falliscono ovunque; il caso DATE fallisce solo su `sqlite`.
- [ ] **Step 3 — Implementazione:** riscrivi solo `finder_conditions_from_args()`.
  1. Estrai le opzioni con `extract_and_validate_options($args)`. Se un hash nudo finale è diventato `conditions`, toglilo anche da `$args`: oggi non viene rimosso.
  2. Nessuna condizione di pk se `$args` è vuoto, oppure è `[null]` o `[[]]`.
  3. Un unico argomento hash diventa `conditions`, come oggi.
  4. In tutti gli altri casi è pk: con un argomento, quel valore (scalare o lista); con più argomenti, la lista.
  5. Ogni valore passa per `date_pk_finder_value()`, poi per `pk_conditions()`.
- [ ] **Step 4 — Esempio:** accanto a `find(0)` → `RecordNotFound`, stampa `count(0)`, `exists(0)` e `count(1, 2, 3)` con l'SQL.
- [ ] **Step 5 — Commit:** `fix: build pk conditions for falsy and multiple pk arguments in exists() and count() (#33)`

**PR — Backward compatibility:**
- `exists()`/`count()` con pk falsy o più pk ora coincidono con `find()`: `exists(0)` passa da true a false; `count(1,2,3)` da 1 a 3.
- Su PG `''` su pk intera lancia 22P02 come `find('')`.
- Monolite: nessuna chiamata con pk scalari. Il suo `RCModel::count` scarta da sé i valori falsy: è un bug lato monolite, fuori da questo plan.

**Release note:** `**BREAKING (behavior):** exists()/count() treat 0, '0', '' and false as primary-key values and several pk arguments as an IN list, like find() — exists(0) returned true and count(1,2,3) counted only id 1 (#33)`

### Task 5 — #38: `first()`/`last()` onorano `offset` e `limit 0`

**Issue:** #38 · **Branch:** `fix/38-first-last-offset` · **Decisione:** D3 · **Dipende da:** — · **Spec:** §4.1

**Files:**
- Modify: `lib/Model.php`: `find()` caso `'first'` (2134-2137); docblock di `first()`, `last()`, `find()` (2029-2104).
- Modify: `lib/Relationship.php`:
  - in `HasMany::load()` (1087-1090), nel ramo has_one che chiama `find('first')`, togli `limit` e `offset` dichiarati;
  - aggiorna il commento di `eager_window()` (~402).
- Create: `test/FirstLastOffsetTest.php`.
- Example: `examples/finders/finders.php`, dopo il ciclo `last()` (~85).

**Comportamento richiesto** (fixture `authors` ordinati per `author_id`):

| Chiamata | Oggi | Dopo |
|---|---|---|
| `first(['order' => 'author_id', 'offset' => 1])` | 1 | 2 |
| `first(['order' => 'author_id', 'offset' => 10])` | 1 | `null` |
| `last(['order' => 'author_id asc', 'offset' => 1])` e `last(['offset' => 1])` | 4 | 3 |
| `find('first', ['limit' => 3, 'order' => 'author_id'])` | 1, `LIMIT 1` | invariato |
| `first(['limit' => 0])`, `first(['limit' => '0'])` | 1 | `null` |
| `first(['order' => 'author_id', 'offset' => -1])` | 1 | 1 (negativo = 0) |
| `find_by_name(['Tito', 'Bill Clinton'], ['order' => 'author_id', 'offset' => 1])` | 1 | 3 |
| `first(2, ['offset' => 1])` | 2 | `RecordNotFound`, come già `find(2, ['offset' => 1])` |
| has_one con `limit`/`offset` dichiarati (lazy ed eager) | ignorati | ignorati (invariato) |

- [ ] **Step 1 — Test RED** in `test/FirstLastOffsetTest.php`:
  - un metodo per ogni riga della tabella, tranne l'ultima;
  - guardia: `find('first', ['limit' => 3])` e `last_sql` contiene il `LIMIT 1` nella sintassi dell'adapter (come `RelationshipTest:446`).
  - Restano verdi:
    - `ActiveRecordFindTest.php:188-260`, incluso `test_gh_37_last_with_order_on_column_containing_desc`;
    - tutta `RelationshipEagerLazyParityTest`, in particolare `test_eager_has_one_ignores_a_declared_limit_and_offset_like_the_lazy_load`;
    - `RelationshipTest::test_has_many_with_sql_clause_options`.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`: falliscono i casi con offset e con limit 0.
- [ ] **Step 3 — Implementazione:**
  ```php
  case 'first':
      // a caller's offset is honoured; a first/last find returns one record:
      // any limit becomes 1, an explicit 0 / '0' is LIMIT 0 (no record) as everywhere since #34
      $options['limit'] = in_array($options['limit'] ?? null, [0, '0'], true) ? 0 : 1;
      $options['offset'] = max(0, intval($options['offset'] ?? 0));
  ```
  Più `unset($options['limit'], $options['offset'])` nel ramo has_one di `HasMany::load()`, così il test di parità di #133 resta invariato.
- [ ] **Step 4 — Esempio:** `first(['order' => 'price', 'offset' => 1])` (il secondo più economico) e `last(['order' => 'price', 'offset' => 1])` (il secondo più caro), con l'SQL.
- [ ] **Step 5 — Commit:** `fix: honour a caller's offset and limit 0 in first() and last() (#38)`

**PR — Backward compatibility:**
- I risultati cambiano solo se il chiamante passa `offset` o `limit` 0; effetto collaterale: `first(pk, ['offset' => n])` diventa `RecordNotFound` come `find()`.
- has_one resta invariato.
- Nessun parametro né tipo aggiunto: due modelli del monolite ridefiniscono `last()` senza parametri.
- Monolite: 0 chiamate con offset o limit.

**Release note:** `first()/last()/find('first'|'last') honour a caller's offset (last() counts it from the end) and treat limit 0 as no record, consistent with #34 (#38)`

### Task 6 — #55: dopo l'insert la pk ha lo stesso tipo che dopo `find()`

**Issue:** #55 · **Branch:** `fix/55-cast-insert-id` · **Decisione:** D4 · **Dipende da:** — · **Spec:** §4.1

**Files:**
- Modify: `lib/Model.php`, `insert()` (~994-998, assegnazione da `insert_id()`).
- Modify: `lib/Connection.php`: solo il docblock di `insert_id()` (337-341), `@return string|false`.
- Modify: `test/ActiveRecordWriteTest.php`, `test/MysqlBigintUnsignedTest.php` (`MariadbBigintUnsignedTest` lo eredita).
- Example: `examples/attributes/attributes.php`, più la cella in `examples/README.md` e la tabella del `README.md` (~267).

**Comportamento richiesto:**
- La pk letta da `insert_id()` passa per `$column->cast($value, static::connection())`. Non usare `assign_attribute()`, che la marcherebbe dirty.
- Esempi del risultato:
  - `Author::create([...])->author_id` → `int`, uguale a quello restituito da `find()`;
  - `create(['author_id' => 50, ...])` su MySQL, MariaDB e SQLite → `50` int;
  - `BIGINT UNSIGNED` pari a `PHP_INT_MAX` → int; `'9223372036854775808'` → stringa esatta (#131);
  - `to_array()`/`to_json()` di un modello appena creato → `"author_id":1`.
- `Connection::insert_id()` resta invariato.

- [ ] **Step 1 — Test RED:**
  - `ActiveRecordWriteTest::test_pk_after_create_has_the_same_type_as_after_find`:
    - `$a = Author::create(['name' => 'Typed']); $f = Author::find($a->author_id);`
    - `assert_same($f->author_id, $a->author_id); assert_true(is_int($a->author_id)); assert_true(is_int($a->to_array()['author_id']));`
  - `ActiveRecordWriteTest::test_explicit_pk_after_create_is_an_int`: `assert_same(50, Author::create(['author_id' => 50, 'name' => 'Fifty'])->author_id)`.
  - `MysqlBigintUnsignedTest::test_auto_increment_pk_at_php_int_max_is_an_int` (RED) e `…::test_auto_increment_pk_beyond_php_int_max_stays_an_exact_string` (guardia):
    - usano una tabella dedicata `BIGINT UNSIGNED AUTO_INCREMENT` creata e droppata nel test;
    - impostano `AUTO_INCREMENT = 9223372036854775807` / `…808` con `ALTER TABLE`.
- [ ] **Step 2 — RED** su `mysql`, `mariadb`, `sqlite`, `pgsql`: falliscono i test di tipo (oggi la pk è una stringa); su PG fallisce il caso senza pk esplicita.
- [ ] **Step 3 — Implementazione:** una riga in `insert()` più il docblock. La semantica di `Column::cast(INTEGER)` (#131) va già bene.
- [ ] **Step 4 — Esempio:** "subito dopo `save()` la pk è un int come dopo `find()`", stampando `var_export($m->id)` prima e dopo `find()`.
- [ ] **Step 5 — Commit:** `fix: cast the insert id through the pk column so create() and find() agree (#55)`

**PR — Backward compatibility:**
- Il tipo della pk dopo `create()`/`save()` di un record nuovo passa da stringa a int (fino a `PHP_INT_MAX`).
- `to_json()` emette `"id":1` invece di `"id":"1"`. I confronti `===` su id appena creati iniziano a funzionare.
- I modelli serializzati prima dell'upgrade conservano la stringa finché non vengono ricaricati.
- Monolite: 13 punti restituiscono al frontend il JSON di un modello appena salvato; nessun `strict_types`.

**Release note:** `**BREAKING (behavior):** after create()/save() the auto-generated primary key is cast like any attribute (int up to PHP_INT_MAX, exact string beyond), so it matches find() — previously the string returned by PDO::lastInsertId() (#55)`

### Task 7 — #65: `tables()` su SQLite elenca solo tabelle e viste

**Issue:** #65 · **Branch:** `fix/65-sqlite-tables-filter` · **Decisione:** D10 · **Dipende da:** T2 · **Spec:** §4.3

**Files:**
- Modify: `lib/adapters/SqliteAdapter.php`, `query_for_tables()` (102-105).
- Modify: `lib/Connection.php`: docblock di `tables()` (449-453) con il contratto per adapter.
- Modify: `test/SqliteAdapterTest.php` (~115-125; aggiorna il commento "see GH-65").
- Example: `examples/simple/simple.php`.

**Comportamento richiesto:**
- Su SQLite `tables()` restituisce tabelle e viste, escludendo indici, autoindex, trigger, `sqlite_sequence` e `sqlite_stat*`.
- SQL: `SELECT name FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite\_%' ESCAPE '\'`, mantenendo l'alias `name`.
- MySQL e PG restano invariati. Il docblock documenta il contratto per adapter:
  - MySQL: tabelle e viste del database corrente (MariaDB anche le TEMPORARY);
  - PG: tabelle base di tutti gli schemi non di sistema, senza viste;
  - SQLite: tabelle e viste.

- [ ] **Step 1 — Test RED** in `SqliteAdapterTest`, con connessione fissata, quindi gira in ogni suite:
  - `test_tables_lists_only_tables_and_views`: crea, con try/finally e DROP:
    - `v65_users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, name TEXT)`;
    - un indice `v65_idx` e la vista `v65_users_view`;
    - il trigger `v65_trg`;
    - `v65_auto (id INTEGER PRIMARY KEY AUTOINCREMENT)`, con un insert che crea `sqlite_sequence`.

    `tables()` contiene `v65_users`, `v65_users_view`, `v65_auto`; non contiene `v65_idx`, `v65_trg`, `sqlite_sequence` né altri nomi `sqlite_*`.
  - `test_tables_keeps_a_table_named_like_the_internal_prefix`: `CREATE TABLE sqliteabc (id INTEGER)` è presente. Un `LIKE` senza escape la escluderebbe.
- [ ] **Step 2 — RED** su `sqlite`.
- [ ] **Step 3 — Implementazione:** solo la query e il docblock.
- [ ] **Step 4 — Esempio:** su uno schema con un indice, una vista e un trigger, stampa `ConnectionManager::get_connection()->tables()`.
- [ ] **Step 5 — Commit:** `fix: list only tables and views in SQLite's tables() (#65)`

**PR — Backward compatibility:** solo SQLite. `tables()` non restituisce più indici, trigger e tabelle interne; le viste restano. Monolite: `tables()` compare solo in 2 migration MySQL.

**Release note:** `Connection::tables() on SQLite no longer returns indexes, triggers and internal sqlite_* tables (views are still listed, as on MySQL) (#65)`

---

## Ondata 3 — Validazioni

Le sezioni di esempio di T8, T10 e T11 si aggiungono **in coda** a `examples/validations/validations.php`. Se due PR entrano in conflitto lì, si risolve mergiando il ponte nel branch.

### Task 8 — #51: uniqueness onora `allow_null`/`allow_blank`

**Issue:** #51 · **Branch:** `fix/51-uniqueness-allow-null-blank` · **Decisione:** D6 · **Dipende da:** — · **Spec:** §4.2

**Files:**
- Modify: `lib/Validations.php`: docblock (571-594) e corpo (595-640) di `validates_uniqueness_of()`. Riusa `is_null_with_option()` e `is_blank_with_option()` (676-687).
- Modify: `test/ValidationsTest.php`.
- Example: `examples/validations/validations.php`, con una tabella in `validations.sql` e un modello in `examples/validations/models/`.

**Comportamento richiesto** (vale per regole su uno o più campi, ad esempio `[['name', 'secondary_author_id'], 'allow_blank' => true]`):
- **`allow_blank`:** se uno qualsiasi dei campi elencati è blank secondo `Utils::is_blank` (`null`, `''`, `[]`, `false`), la regola viene saltata e il record è valido. Uno spazio `' '` non è blank: viene controllato come oggi.
- **`allow_null`:** se uno qualsiasi dei campi è `null`, la regola viene saltata.
- **Senza opzioni:** se un campo è `null` la query viene saltata e il record è valido. Il risultato è identico a oggi, ma non si lega più `= NULL`.
- **Valori non null e non blank:** stesso SQL di oggi.
- **Docblock:** rimuovi `with`; documenta `allow_null`, `allow_blank` e il caso NULL.
- **Esclusione del record stesso:** resta su `pk[0]` come oggi; la estende T9.

- [ ] **Step 1 — Test RED** in `ValidationsTest`. In ogni test imposta `BookValidations::$validates_presence_of = []` e popola con `Book::create()`. Non usare valori che differiscono solo per maiuscole: MySQL ha collation `_ci`.
  - `test_uniqueness_allow_blank_skips_a_blank_duplicate`: con `Book::create(['name' => ''])` e la regola `[['name', 'allow_blank' => true]]`, `(new BookValidations(['name' => '']))->is_valid()` è true.
  - `test_uniqueness_allow_blank_on_several_fields_skips_when_any_is_blank`: con `Book::create(['name' => '', 'secondary_author_id' => 2])` e la regola `[[['name', 'secondary_author_id'], 'allow_blank' => true]]`, il record `['name' => '', 'secondary_author_id' => 2]` è valido.
  - Guardie (verdi già oggi):
    - senza `allow_blank` un duplicato `''` è invalido con "Name must be unique";
    - `' '` con `allow_blank` viene controllato;
    - un NULL senza opzioni resta valido;
    - restano verdi l'esclusione del record stesso e i test di uniqueness in `DateBindValuesTest`.
  - `test_uniqueness_with_a_null_value_runs_no_query`: con `name = null`, `is_valid()` non deve eseguire nessun `SELECT EXISTS`. Verificalo con il contatore o il logger delle query, oppure confrontando `Book::connection()->last_query` prima e dopo.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`: falliscono i casi `allow_blank` e il test "nessuna query".
- [ ] **Step 3 — Implementazione:** prima di costruire `$sql`, valuta le opzioni sui valori reali dei campi (`get_real_attribute_name`). Le condizioni restano **posizionali**, così i percorsi di #142/#144 non vengono toccati.
- [ ] **Step 4 — Esempio:** un `nickname` unico e opzionale con `allow_blank`. Due nickname vuoti si salvano, un duplicato non vuoto viene rifiutato.
- [ ] **Step 5 — Commit:** `fix: honour allow_null/allow_blank in validates_uniqueness_of and never bind = NULL (#51)`

**PR — Backward compatibility:**
- La validazione può solo allentarsi: un valore blank con `allow_blank`, o null con `allow_null`, non è più segnalato come duplicato.
- Con un NULL non parte più nessuna query; il risultato è identico.
- Monolite: nessun `validates_uniqueness_of`.

**Release note:** `validates_uniqueness_of honours allow_null/allow_blank (any listed field) and skips the query for NULL values instead of binding "= NULL"; the bogus 'with' option is gone from the docs (#51)`

### Task 9 — #123 + #155: modelli senza pk e uniqueness su pk composta

**Issue:** #123, #155 · **Branch:** `fix/123-pkless-models` · **Decisione:** D8 · **Dipende da:** T8 · **Spec:** §4.2

**Files:**
- Modify: `lib/Model.php`:
  - `reload()` (1666-1701);
  - scorciatoia `id`: in `__set` (514-517), nella lettura (612-623) e, se serve, in `__isset` (421-424);
  - guardia del mass assignment (1527-1531).
- Modify: `lib/Validations.php`: esclusione del record stesso in `validates_uniqueness_of()` (605-625).
- Modify (test da ribaltare o aggiornare):
  - `test/ActiveRecordTest.php` (188-196);
  - `test/ModelIssetTest.php` (164-172);
  - `test/StrictMassAssignmentTest.php` (152-156);
  - `test/ActiveRecordTest.php` (198-202) va solo verificato: resta com'è.
- Create/Modify: test di `reload` in `test/ActiveRecordWriteTest.php` e di uniqueness in `test/ValidationsTest.php`.
- Example: `examples/simple/simple.php` (`SimplePageVisit` non ha pk), più le celle "Demonstrates" (`examples/README.md:21`, `README.md:262`).

**Comportamento richiesto:**
- **`reload()`:** i controlli avvengono prima di toccare relazioni o attributi.
  - Senza pk definita: `ActiveRecordException("Cannot reload, no primary key defined for: <Class>")`.
  - Con una colonna di pk assente dagli attributi (caricata con un `select` che la esclude): `ActiveRecordException("Cannot reload, primary key not loaded for: <Class> (<colonne>)")`.
  - Altrimenti il comportamento resta quello di oggi.
- **Uniqueness, esclusione del record stesso:**
  - record nuovo (una colonna di pk nulla): `<pk0> IS NOT NULL`, stesso SQL di oggi;
  - record salvato con pk singola: `<pk> != ?`, stesso SQL;
  - record salvato con pk composta: `NOT (<a> = ? AND <b> = ?)` (#155);
  - tabella senza pk, record nuovo: nessuna esclusione, solo le condizioni sui campi;
  - tabella senza pk, record salvato (`!is_new_record()`): `ActiveRecordException("Cannot validate uniqueness without a primary key: <Class>")`.
  - Le regole di T8 su null e blank restano.
- **`id` su una tabella senza pk:** è un attributo sconosciuto come gli altri.
  - `$m->id = X` → `UndefinedPropertyException`.
  - Mass assignment (`new M(['id' => …])`, `set_attributes`, `update_attributes`, `create`): `id` è trattato come qualunque chiave sconosciuta. La guardia lo riporta come `attribute 'id'` invece di `attribute ''`.
  - La lettura dell'attributo `''` viene rimossa.

- [ ] **Step 1 — Test RED.** Fixture esistenti: `pkless_items` (`code`, `name`) con il modello `PklessItem`; `coded_items` (pk composta `owner` + `code`, righe `acme/abc 'one'` e `acme/def 'two'`).
  - `test_reload_without_a_primary_key_throws`: `PklessItem::first()->reload()` → `ActiveRecordException` con il messaggio sopra. Oggi è un `Error` più warning; su PHP 8.5 anche una deprecation.
  - `test_reload_with_the_primary_key_not_selected_throws`: `Book::first(['select' => 'name'])->reload()` → `ActiveRecordException`.
  - `test_uniqueness_on_a_pkless_table_validates_new_records`, con una classe inline su `pkless_items` e la regola `[['name']]`:
    - un duplicato nuovo della riga di fixture è invalido ("Name must be unique");
    - un valore nuovo è valido, e `create()` lo inserisce.
  - `test_uniqueness_on_a_saved_pkless_record_throws`.
  - `test_uniqueness_excludes_self_by_every_composite_pk_column` (#155): con una classe inline su `coded_items` e uniqueness su `name`, carica `acme/def`, imposta `name = 'one'` → invalido (oggi è valido).
  - `test_assigning_id_on_a_pkless_model_throws` e `test_mass_assigning_id_on_a_pkless_model_is_an_unknown_attribute` (`new PklessItem(['id' => 5, 'code' => 1])`).
  - Aggiorna i test fissati elencati in **Files**.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`, e su PHP 8.5 per il test di `reload`.
- [ ] **Step 3 — Implementazione** come descritto sopra. Il controllo "pk non caricata" usa `array_key_exists` sugli attributi per ogni colonna di `get_primary_key()`.
- [ ] **Step 4 — Esempio** (`examples/simple/simple.php`):
  - `reload()` su `SimplePageVisit` stampa il messaggio dell'eccezione;
  - un modello senza pk con `validates_uniqueness_of` crea una riga e rifiuta un duplicato;
  - `$visit->id = 1` → `UndefinedPropertyException`.
- [ ] **Step 5 — Commit:** `fix: clear exceptions for reload, uniqueness and id on pk-less models; exclude self by every composite pk column (#123, #155)`

**PR — Backward compatibility:**
- `reload()`: da `Error` PHP a `ActiveRecordException`.
- uniqueness: su tabelle senza pk diventa utilizzabile per i record nuovi; su pk composte diventa più severa, perché i duplicati che oggi sfuggono diventano errori.
- `id` su tabelle senza pk: fallisce subito invece che al `save()`; cambiano 3 test che fissavano `''`.
- Monolite: tutte le tabelle hanno una pk; 241 `reload()` su modelli con pk.

**Release note:** `**BREAKING (behavior):** pk-less models: reload() and a uniqueness check on a saved record throw ActiveRecordException, assigning id is an unknown attribute; validates_uniqueness_of excludes the record by every column of a composite pk (missed duplicates are now reported) (#123, #155)`

### Task 10 — #49: numericality esatta tra interi

**Issue:** #49 · **Branch:** `fix/49-numericality-exact-integers` · **Decisione:** D5 · **Dipende da:** — · **Spec:** §4.2

**Files:**
- Modify: `lib/Validations.php`: `validates_numericality_of()` e il suo docblock (309-404), più helper privati nella stessa classe.
- Modify: `test/ValidatesNumericalityOfTest.php`.
- Example: `examples/validations/validations.php`.

**Comportamento richiesto:**
- **Classificazione** del valore e di ogni limite, dopo i controlli esistenti:
  - **INT:** int PHP, oppure stringa `/\A[ \t\n\r\v\f]*[+-]?\d+[ \t\n\r\v\f]*\z/` che rientra nell'int range;
  - **BIG:** stessa forma ma fuori range, canonicalizzata (segno + cifre senza zeri iniziali; `-0` diventa `0`);
  - **FLOAT:** tutto il resto, cioè float PHP, stringhe decimali o con esponente, INF e NAN.
- **Confronti:**
  - INT×INT in modo nativo;
  - INT/BIG con INT/BIG tramite comparatore di stringhe di cifre (segno, poi lunghezza, poi `strcmp`);
  - se c'è un FLOAT, in float con gli stessi operatori di oggi (`>`, `>=`, `==`, `<`, `<=`). Mai `<=>`, che con NAN restituisce 1.
- **Parità:**
  - INT: `& 1`;
  - BIG: l'ultima cifra;
  - FLOAT: il troncamento di #59; con `|x| ≥ 2^53` è sempre pari, senza cast, quindi senza il warning di PHP 8.5.
- **Messaggio:** i limiti INT/BIG si stampano con le cifre esatte; i limiti FLOAT come oggi, con `(string)(float)`.
- **Invariati:**
  - odd/even attivati dalla sola presenza dell'opzione (`'odd' => null` vale);
  - `only_integer` richiede `true` stretto;
  - la regex con `\Z`;
  - NAN fallisce sia `greater_than` sia `less_than`;
  - `Utils::is_odd()` non si tocca;
  - nessuna dipendenza nuova (niente bcmath né gmp).

- [ ] **Step 1 — Test RED** in `ValidatesNumericalityOfTest`. Usa `is_valid()`, non `assert_validity()`: `numeric_test` è `VARCHAR(10)`. Usa una colonna INT (es. `author_id`) per i valori int PHP.
  - Validi: `'9223372036854775806'` e `PHP_INT_MAX - 1` con `less_than => PHP_INT_MAX`.
  - `9007199254740993`: invalido con `equal_to => 9007199254740992`; valido con `greater_than => 9007199254740992`, anche con `only_integer`.
  - `'18446744073709551614'`:
    - valido con `less_than => '18446744073709551615'`;
    - invalido con `equal_to => '18446744073709551615'`;
    - con `only_integer` e `less_than_or_equal_to => '…614'`, il valore `'…615'` è invalido.
  - `PHP_INT_MIN + 1` è valido con `greater_than => PHP_INT_MIN`.
  - Parità: `9007199254740993` con `odd` è valido; `'18446744073709551615'` con `odd` è valido; `'18446744073709551614'` con `even` è valido. Su PHP 8.5 nessun warning.
  - Messaggio: con il limite `less_than => 100000000000000` il testo contiene `must be less than 100000000000000`.
  - Guardie (verdi oggi):
    - limiti float `0.5` e `19.99`;
    - `'5.0'` con `equal_to => 5` è valido;
    - il limite `'05'` si stampa `5`; il limite float `1e15` si stampa `1.0E+15`;
    - NAN sulla colonna DECIMAL `special` fallisce `gt` e `lt`;
    - `'odd' => null` resta attivo;
    - i test del troncamento di #59.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, e su PHP 8.5 per la parità oltre l'int range.
- [ ] **Step 3 — Implementazione:** helper privati per classificazione, confronto, parità e resa del messaggio. Nel report includi una matrice old-vs-new (valori × limiti × opzioni, come per #131) che mostri che cambiano solo le celle previste.
- [ ] **Step 4 — Esempio:** un modello con `ref` e `less_than => PHP_INT_MAX`. `PHP_INT_MAX - 1` è valido; `PHP_INT_MAX` è invalido e il messaggio mostra il limite esatto. Più odd/even su `9007199254740993`. Solo `is_valid()`.
- [ ] **Step 5 — Commit:** `fix: compare integer values and bounds exactly in validates_numericality_of (#49)`

**PR — Backward compatibility:**
- I risultati cambiano solo quando entrambi gli operandi sono interi e almeno uno supera 2^53.
- Il testo del messaggio cambia solo per limiti interi ≥ 1e14 (esempio: `9.2233720368548E+18` → `9223372036854775807`).
- Il warning di PHP 8.5 sparisce.
- Monolite: nessun `validates_numericality_of`.

**Release note:** `validates_numericality_of compares integers exactly beyond 2^53 (values and bounds, incl. odd/even) and prints integer bounds exactly in its messages (#49)`

### Task 11 — #57: length conta caratteri, byte per le colonne binarie

**Issue:** #57 · **Branch:** `fix/57-length-counts-characters` · **Decisione:** D7 · **Dipende da:** — · **Spec:** §4.2

**Files:**
- Modify: `lib/Validations.php`: `validates_length_of()` e il suo docblock (466-569; il conteggio è alla riga 552), più un helper privato di conteggio.
- Modify: `test/ValidatesLengthOfTest.php`.
- Modify/Create: una tabella `length_samples (id, label VARCHAR(50), payload <binario>)`:
  - in tutti i `test/sql/*.sql`: `VARBINARY(16)` su mysql, `BYTEA` su pgsql, `BLOB` su sqlite, lontano dalle definizioni di `big_ids`/`coded_items`;
  - `test/fixtures/length_samples.csv` (anche solo l'intestazione);
  - un modello in `test/models/`.
- Example: `examples/validations/validations.php`, sezione `LengthRangeUser`.

**Comportamento richiesto:**
- **Conteggio:** `mb_strlen($v, 'UTF-8')` se la funzione esiste. Altrimenti `preg_match_all('/./su', $v)`, se non restituisce `false`. Altrimenti `strlen($v)` (UTF-8 non valido senza mbstring).
- **Byte:**
  - se l'attributo, risolto con `get_real_attribute_name`, corrisponde a una colonna il cui `raw_type` soddisfa `/binary|blob|bytea/i`, si usa `strlen`;
  - la nuova opzione per regola `'encoding' => '8bit'` forza `strlen` su qualsiasi colonna; qualunque altro valore di `encoding` viene ignorato;
  - gli attributi senza colonna (getter) contano caratteri.
- **Invariati:** messaggi e token `%d`, `composer.json`. Il docblock documenta l'unità di misura e `encoding`.

- [ ] **Step 1 — Test RED** in `ValidatesLengthOfTest` (`BookLength` su `books.name`, `is_valid()`):
  - `'héllo'` con `maximum 5`, `is 5` e `within [1,5]` → valido; `'ééééé'` con `in [1,5]` → valido; `'😀😀😀'` con `maximum 5` → valido; `"e\u{301}"` con `is 2` → valido.
  - `'héllo'` con `minimum 6` → invalido, con "is too short (minimum is 6 characters)".
  - Sul modello di `length_samples`, un valore di 6 byte in `payload` con `maximum 5` → invalido (byte). Lo stesso valore in `label` → valido (caratteri).
  - `'héllo'` con `maximum 5` e `'encoding' => '8bit'` → invalido.
  - Fallback senza mbstring: test via `ReflectionMethod` sull'helper privato, con il flag mbstring forzato a false (es. un parametro `?bool $mbstring = null`).
  - Guardie: tutti i casi ASCII esistenti; il messaggio custom `too_long` con `%d`.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`.
- [ ] **Step 3 — Implementazione:** l'helper privato `string_length(string $value, bool $bytes, ?bool $mbstring = null): int` e la scelta dei byte in base a colonna e opzione. Il precedente da seguire è `lib/Relationship.php:445-453`.
- [ ] **Step 4 — Esempio:** `'Niccolò'` (7 caratteri, 8 byte) con `within => [2, 7]` → valido; più un caso `minimum`.
- [ ] **Step 5 — Commit:** `fix: count characters in validates_length_of, bytes for binary columns (#57)`

**PR — Backward compatibility** (solo per i valori multibyte):
- `maximum`, `is` e l'estremo superiore di `within`/`in` diventano più permissivi.
- `minimum`, `is` e l'estremo inferiore diventano più severi (esempio: `'perché'` conta 6, non 7).
- Le colonne binarie restano in byte. Nuova opzione `'encoding' => '8bit'`.
- Un `maximum` scelto vicino al limite in byte delle colonne TEXT di MySQL non le protegge più: usa `'encoding' => '8bit'`.
- Monolite: nessun `validates_length_of`.

**Release note:** `**BREAKING (behavior):** validates_length_of counts characters (mb_strlen, PCRE fallback) instead of bytes, except on binary/blob/bytea columns; new per-rule option 'encoding' => '8bit' forces bytes (#57)`

---

## Ondata 4 — Tipi, introspezione e cache

### Task 12 — #146: i float vengono legati con almeno 15 cifre significative

**Issue:** #146 · **Branch:** `fix/146-float-bind-precision` · **Decisione:** D12 · **Dipende da:** T7 (stesso file `SqliteAdapter.php`) · **Spec:** §4.3

**Files:**
- Modify: `lib/Connection.php`: `bind_values()` (383-410) e un nuovo helper.
- Modify: `lib/adapters/SqliteAdapter.php`: `bind_values()` (59-82).
- Create: `test/DecimalPrecisionTest.php`.
- Create: la tabella `decimal_amounts (id, amount DECIMAL(20,2), fine DECIMAL(20,10))` (`NUMERIC` su pgsql) in tutti i `test/sql/*.sql`, più `test/fixtures/decimal_amounts.csv` e il modello `DecimalAmount`.
- Example: `examples/attributes/attributes.php` e `attributes.sql`.

**Interfaces:**
- Produce `protected static function format_float(float $value): string` su `Connection`, usato da entrambi i `bind_values()`.
  - Con l'ini `precision` a `-1`: rappresentazione round-trip più corta (`var_export($value, true)`, che usa `serialize_precision`).
  - Altrimenti: `sprintf('%.' . max(15, (int) ini_get('precision')) . 'G', $value)`.
  - INF e NAN restano come oggi (`(string)`).

**Comportamento richiesto:**
- Ogni float legato a una query (insert, update, condizioni) passa per `format_float()`.
- Il tipo dell'attributo, la lettura e il JSON restano invariati.

- [ ] **Step 1 — Test RED** in `DecimalPrecisionTest`. Usa valori di al massimo 15 cifre significative, perché il REAL di SQLite ne conserva 15.
  - `test_create_keeps_fifteen_significant_digits`: `DecimalAmount::create(['amount' => '1234567890123.45'])`, poi una SELECT grezza della colonna → `1234567890123.45` (oggi `…123.40`).
  - `test_dirty_save_after_reload_keeps_the_value`: reload, modifica di un'altra colonna, `save()` → valore invariato.
  - `test_finder_with_a_read_value_finds_the_row`: `DecimalAmount::find_by_amount($m->amount)` trova la riga (oggi no).
  - `test_ten_decimals`: `'12345.6789012345'` in `fine` → esatto.
  - Guardie:
    - `0.1 + 0.2` viene salvato come `0.30`, e la condizione `amount = ?` con `0.1 + 0.2` trova la riga;
    - con `ini_set('precision', '17')` (ripristinato in `tear_down`) i valori salvati sono gli stessi;
    - restano verdi `ActiveRecordTest.php:489-493`, `ValidatesNumericalityOfTest.php:234-241` e `ColumnTest.php:94`.
- [ ] **Step 2 — RED** su `mysql`, `mariadb`, `sqlite`, `pgsql`.
- [ ] **Step 3 — Implementazione:** l'helper e il pre-trattamento dei float nei due `bind_values()`. Su SQLite i float restano `PARAM_STR`, come oggi.
- [ ] **Step 4 — Esempio:** round trip di un `DECIMAL(20,2)` con 15 cifre significative, stampato prima e dopo il salvataggio.
- [ ] **Step 5 — Commit:** `fix: bind floats with at least 15 significant digits so DECIMAL values round-trip (#146)`

**PR — Backward compatibility:**
- Cambia il testo legato dei float con una quindicesima cifra non nulla, solo dove oggi veniva troncato.
- Le uguaglianze su colonne DOUBLE/FLOAT tra righe scritte a 14 cifre e float calcolati con 15 cifre potrebbero, raramente, non coincidere più.
- Oltre 15 cifre significative il limite resta (documentato).
- Le colonne REAL vanno in #152.
- Monolite: oggi nessuna perdita reale (colonne FLOAT, DECIMAL fino a (20,4)).

**Release note:** `Floats are bound with max(15, precision) significant digits (shortest round-trip with precision=-1): DECIMAL values up to 15 significant digits no longer lose their last digit on save or in finder conditions (#146)`

### Task 13 — #67: tabella mancante → `DatabaseException` su PG e SQLite

**Issue:** #67 · **Branch:** `fix/67-missing-table-introspection` · **Decisione:** D11 · **Dipende da:** T2 (override di `columns()` su SQLite) · **Spec:** §4.3

**Files:**
- Modify: `lib/Connection.php`: `columns()` (308-323) e un nuovo hook protetto.
- Modify: `lib/adapters/PgsqlAdapter.php`: override dell'hook.
- Modify: `test/helpers/AdapterTest.php` (gira su tutti e quattro gli adapter), `test/PgsqlAdapterTest.php`, `test/CacheSchemaIntegrationTest.php`, più un test a livello di modello in `test/ActiveRecordTest.php`.
- Example: `examples/simple/simple_with_options.php`.

**Interfaces:**
- Produce `protected function relation_exists_without_columns(string $table): bool`.
  - Default su `Connection`: `false`.
  - `PgsqlAdapter`: `SELECT to_regclass(?) IS NOT NULL`, con il nome passato tale e quale. Così un `'"s".t'` già quotato si risolve, e la regola si applica solo al ramo con zero righe.
  - Consumato da T15: un `[]` per una tabella mancante non arriva mai in cache.

**Comportamento richiesto:**
- `Connection::columns()`: se l'introspezione restituisce zero colonne e `relation_exists_without_columns()` è false, lancia `DatabaseException("Table or view not found: <table>")`.
  - Su SQLite questo vale sempre (non esistono tabelle a zero colonne).
  - Su PG una relazione esistente a zero colonne, o un modello `$db` colpito da #143, restituisce `[]` come oggi.
- L'eccezione nasce dentro la closure di `Cache::get`, quindi non viene mai messa in cache.
- MySQL e MariaDB restano invariati: lanciano già con il loro messaggio PDO.

- [ ] **Step 1 — Test RED.**
  - `AdapterTest::test_columns_of_missing_table_throws`: `$this->connection->columns('v67_no_such_table')` → `DatabaseException`. È RED in `PgsqlAdapterTest` e `SqliteAdapterTest`.
  - `PgsqlAdapterTest::test_existing_zero_column_table_has_no_columns`: con `CREATE TABLE v67_empty ()` creata e droppata nel test, `columns()` → `[]`.
  - Test di modello: una classe inline con `$table_name = 'v67_no_such_table'` → `Model::table()` lancia `DatabaseException`. È RED sulle suite `sqlite` e `pgsql`.
  - `CacheSchemaIntegrationTest::test_missing_table_leaves_no_cache_entry`: dopo l'eccezione non resta nessuna voce `get_meta_data-…` per quella tabella. Ripristina `Cache::$adapter`.
  - `PgsqlAdapterTest::test_missing_table_inside_a_transaction_keeps_it_usable` (Review Focus 4): dentro `Model::transaction()`, cattura la `DatabaseException` della tabella mancante; una write successiva nella stessa transazione e il commit riescono. L'eccezione nasce in PHP e la verifica `to_regclass` non lancia mai.
- [ ] **Step 2 — RED** su `sqlite`, `pgsql` (e `mysql`, dove i test devono essere già verdi).
- [ ] **Step 3 — Implementazione:** il controllo va in `Connection::columns()`, dopo il ciclo delle colonne. Il `columns()` di SQLite (T2) chiama `parent::columns()` per primo.
- [ ] **Step 4 — Esempio:** un `$table_name` sbagliato fallisce subito anche su SQLite, con il messaggio stampato.
- [ ] **Step 5 — Commit:** `fix: raise DatabaseException for a missing table on Postgres and SQLite (#67)`

**PR — Backward compatibility:**
- Su PG e SQLite il primo uso di un modello su una tabella inesistente lancia `DatabaseException` invece di costruire una Table vuota, e `Connection::columns('mancante')` lancia invece di restituire `[]`.
- La classe è la stessa di MySQL; il messaggio è diverso dal testo PDO di MySQL.
- Monolite: produzione invariata. **Prima del rilascio va eseguita la sua suite SQLite**, che crea solo un sottoinsieme di tabelle.

**Release note:** `**BREAKING (behavior):** on Postgres and SQLite a model whose table does not exist throws DatabaseException("Table or view not found: …") at introspection, like MySQL, instead of yielding a zero-column Table (#67)`

### Task 14 — #63: `flush()` rispetta il namespace

**Issue:** #63 · **Branch:** `fix/63-cache-flush-namespace` · **Decisione:** D20 · **Dipende da:** — · **Spec:** §4.4

**Files:**
- Modify:
  - `lib/cache/Memcache.php`: costruttore (22-30), `flush()` (35-38), traduzione delle chiavi in `read`/`write`;
  - `lib/cache/File.php`: costruttore (29-32), `flush()` (37-41);
  - `lib/cache/Redis.php`: `flush()` (73-87), con l'escape del glob;
  - `lib/Cache.php`: docblock di `flush()` e del namespace.
- Modify: `test/CacheTest.php` (memcache), `test/FileCacheTest.php`, `test/RedisCacheTest.php`.
- Create: `examples/cache/cache.php` e `examples/cache/cache.sql`, più le righe in `examples/README.md` e nel `README.md`.

**Interfaces:**
- Produce i costruttori `Memcache::__construct(array $options, array $cache_options = [])` e `File::__construct(array $options, array $cache_options = [])`, dove `$cache_options['namespace']` è opzionale. `Cache::initialize()` li passa già.
- Consumato da T15, che legge le chiavi con generazione.

**Comportamento richiesto:**
- **Memcache con namespace:**
  - la chiave di generazione `<ns>::__phpar_generation` viene inizializzata con `add(<ns>::__phpar_generation, random_int(1, PHP_INT_MAX >> 1), 0)`;
  - le chiavi dati diventano `<ns>::<gen>::<key>`. `read`/`write` traducono le chiavi `<ns>::<key>`, così `Cache::$adapter->read("<ns>::key")` continua a funzionare;
  - `flush()` esegue `increment` sulla chiave di generazione;
  - la generazione si legge a ogni get e write.
- **File con namespace:** `flush()` cancella solo i file il cui nome inizia con `<ns>::` (`scandir` + `str_starts_with`, non `glob`).
- **Redis:** prima di `SCAN MATCH`, fa l'escape di `\`, `*`, `?`, `[`, `]` nel namespace.
- **Senza namespace:** `flush()` svuota tutto su tutti e tre i backend, come oggi (anche i file temporanei orfani).
- **Namespace cambiato a posteriori:** se lo si cambia in `Cache::$options` dopo `initialize()`, l'adapter usa quello ricevuto alla costruzione. Va documentato.

- [ ] **Step 1 — Test RED.**
  - `CacheTest::test_namespaced_flush_keeps_other_namespaces`: memcache via facade. Un flush sotto `nsB` conserva la chiave di `nsA` e una chiave senza namespace.
  - `FileCacheTest::test_namespaced_flush_keeps_other_namespaces`: stesso test sul backend file.
  - `RedisCacheTest::test_flush_escapes_glob_characters_in_the_namespace`: un flush sotto `x*` conserva `xa::k`.
  - Guardie:
    - senza namespace, `flush()` svuota tutto su tutti e tre i backend;
    - `test_interrupted_write_leaves_committed_value_intact` resta verde;
    - una voce scritta prima di un flush con namespace è un miss dopo il flush.
  - Ogni test pulisce il proprio namespace e ripristina `Cache::$adapter` e `Cache::$options`.
- [ ] **Step 2 — RED** su `mysql`: le classi di cache girano in ogni suite, con memcached e redis presenti nell'ambiente Docker.
- [ ] **Step 3 — Implementazione** come descritto sopra.
- [ ] **Step 4 — Esempio:** `examples/cache/cache.php` con SQLite e cache `file://` in una cartella temporanea. Due namespace: dopo il flush di uno, l'altro sopravvive.
- [ ] **Step 5 — Commit:** `fix: scope Cache::flush() to the configured namespace on memcache and file (#63)`

**PR — Backward compatibility:**
- Un flush con namespace su memcache e file non cancella più gli altri namespace né le chiavi senza namespace.
- Le chiavi memcache con namespace cambiano formato, quindi dopo l'upgrade c'è un miss.
- Redis cambia solo per namespace che contengono caratteri glob.
- Senza namespace il comportamento è invariato.
- Monolite: nessuna cache configurata.

**Release note:** `Cache::flush() under a namespace no longer wipes the whole memcached server or cache directory (memcache uses a namespace generation key: namespaced keys change format, one miss after upgrade; Redis escapes glob characters) (#63)`

### Task 15 — #62: `Cache::get` riconosce i valori falsy in cache

**Issue:** #62 · **Branch:** `fix/62-cache-falsy-hits` · **Decisione:** D20 · **Dipende da:** T13, T14 · **Spec:** §4.4

**Files:**
- Modify: `lib/Cache.php`: `get()` (83-96) e la PHPDoc di `$adapter` (15-16), che diventa `File|Memcache|Redis|object|null`.
- Modify: `lib/cache/Memcache.php`, `lib/cache/Redis.php`, `lib/cache/File.php`: nuovo metodo `fetch()`.
- Modify: `test/CacheTest.php`, `test/RedisCacheTest.php`, `test/FileCacheTest.php`.
- Example: `examples/cache/cache.php`, creato da T14.

**Interfaces:**
- Produce `public function fetch(string $key): array` sui tre adapter, che restituisce `[bool $hit, mixed $value]`.
  - Memcache: `get()` più `getResultCode() === \Memcached::RES_SUCCESS`, con le chiavi di generazione di T14.
  - Redis: `GET` → `null` è un miss; `'b:0;'` è un hit con `false`; un altro `unserialize() === false` è un miss (dato corrotto); altrimenti hit.
  - File: il controllo esistente sull'envelope.
- `read()` resta invariato su tutti e tre.

**Comportamento richiesto:**
- `Cache::get()`: se `method_exists(static::$adapter, 'fetch')`, un hit restituisce il valore memorizzato anche se è falsy (`false`, `0`, `''`, `[]`, `'0'`, `0.0`).
- `null` continua a significare "non in cache": la closure riparte a ogni chiamata, come oggi.
- Un adapter custom senza `fetch()` mantiene la logica di truthiness di oggi.
- Il formato dei dati in cache resta invariato.

- [ ] **Step 1 — Test RED.**
  - `CacheTest::test_falsy_values_are_cache_hits`: per ciascun valore in `[false, 0, '', [], '0', 0.0]`, la closure gira una sola volta su due `Cache::get()`. Memcache via facade.
  - `RedisCacheTest::…`: lo stesso test, usando solo GET e SET, perché lo esegue anche il job redis-compat.
  - `FileCacheTest::…`: lo stesso test via facade con `file://`.
  - `CacheTest::test_null_is_not_cached`: con `null` la closure gira a ogni chiamata.
  - `CacheTest::test_custom_adapter_without_fetch_keeps_today_behaviour`: con una classe anonima, la closure falsy gira ogni volta.
  - `CacheSchemaIntegrationTest::test_missing_table_is_never_cached_as_empty` (Review Focus 2): con la cache memcache attiva e i falsy ora memorizzati, due caricamenti di un modello su una tabella mancante lanciano entrambi `DatabaseException`, e non resta nessuna voce `[]` in cache. Su SQLite e PG questo lo garantisce T13.
- [ ] **Step 2 — RED** su `mysql` (classi di cache).
- [ ] **Step 3 — Implementazione** come descritto sopra.
- [ ] **Step 4 — Esempio:** in `examples/cache/cache.php`, una closure che restituisce `false`, `0` e `[]` gira una sola volta.
- [ ] **Step 5 — Commit:** `fix: treat cached falsy values as hits in Cache::get (#62)`

**PR — Backward compatibility:**
- La closure non viene più rieseguita per i risultati falsy già in cache: cambia per chi ha closure con effetti collaterali, e per chi restituiva un valore falsy per dire "non mettere in cache" (deve usare `null`).
- C'è un nuovo metodo pubblico `fetch()` sugli adapter, che potrebbe collidere con una sottoclasse che ne ha già uno con lo stesso nome (nel monolite non ce ne sono).

**Release note:** `Cache::get() treats cached false/0/''/[]/'0'/0.0 as hits instead of recomputing them on every call (null still means "not cached"); bundled adapters gain fetch() (#62)`

---

## Ondata 5 — Postgres

Le prove di questa ondata si mettono preferibilmente in `PgsqlAdapterTest` o in `AdapterTest`: con la connessione fissata entrano nel gate della CI già prima di T22. Per i test di introspezione, ricarica lo schema dal vivo con `Table::clear_cache()` e `Cache::$adapter = null`, poi ripristina entrambi. Il DDL ad hoc va in try/finally, con `DROP … IF EXISTS` (e `DROP SCHEMA … CASCADE` per gli schemi creati dal test).

### Task 16 — #92 (Cat 4 e 5): test adapter-aware al posto degli skip

**Issue:** #92 (Refs) · **Branch:** `test/92-pgsql-adapter-aware-tests` · **Decisione:** D13 · **Dipende da:** — · **Spec:** §4.5

**Files:**
- Modify: `test/ActiveRecordFindTest.php` (`test_find_nothing_with_sql_in_string`, ~:55), `test/ActiveRecordWriteTest.php` (~:410, ~:465), `test/SQLBuilderTest.php` (~:196, ~:238).
- Modify: docblock di `Model::find()` (Cat 4: su PG un valore di pk non valido per il tipo lancia `DatabaseException` 22P02, e dentro una transazione la abortisce) e di `delete_all()`/`update_all()` (Cat 5: su Postgres `limit`/`order` sono ignorati e vengono toccate tutte le righe che soddisfano le condizioni).
- Example: non si applica (solo test e docblock).

**Comportamento richiesto:**
- Nessun cambio a runtime.
- **Cat 4:** quando la connessione è un `PgsqlAdapter`, il test si aspetta `DatabaseException` con `22P02` nel messaggio; sugli altri adapter resta `RecordNotFound`.
- **Cat 5:** i 4 `mark_test_skipped` diventano asserzioni condizionate da `accepts_limit_and_order_for_update_and_delete()`.
  - Se il metodo restituisce true, valgono le asserzioni di oggi.
  - Altrimenti, su PG, `last_sql` non contiene `ORDER BY` né `LIMIT`, e vengono toccate tutte le righe che soddisfano le condizioni: con `delete_all` su `parent_author_id = 2`, più `limit 1` e un `order`, si cancellano 2 righe.

- [ ] **Step 1 — Riscrivi** i 5 test come indicato.
- [ ] **Step 2 — Prima/dopo** su `pgsql`: prima, 1 failure (Cat 4) e 4 skip (Cat 5); dopo, la suite pgsql mostra solo i 4 errori di Cat 1. `mysql` e `sqlite` restano verdi, con le stesse asserzioni di oggi.
- [ ] **Step 3 — Commit:** `test: assert the documented Postgres behaviour instead of skipping (#92)`

**PR — Backward compatibility:** nessuna (test e docblock). **Release note:** `Tests: Postgres-specific behaviour of find() with a non-integer pk and of limit/order in update_all/delete_all is asserted instead of skipped (#92)`

### Task 17 — #147: l'introspezione PG legge solo la relazione risolta dal `search_path`

**Issue:** #147 · **Branch:** `fix/147-pgsql-introspection-search-path` · **Decisione:** D16 · **Dipende da:** T13 · **Spec:** §4.5

**Files:**
- Modify: `lib/adapters/PgsqlAdapter.php`, `query_column_info()` (54-82; il filtro è alla riga 74).
- Modify: `test/PgsqlAdapterTest.php`.
- Example: `examples/sequences/sequences.php`, metà PG: una tabella omonima in un altro schema.

**Comportamento richiesto:**
- La query filtra con `WHERE c.oid = to_regclass(quote_ident(?))` invece di `c.relname = ?`. Il resto della SELECT e dei JOIN resta com'è; se riscrivi un JOIN, aggiungi `NOT a.attisdropped`.
- Facoltativo: limitare `relkind` a tabelle, viste, viste materializzate, tabelle foreign e partizionate.
- La firma `query_column_info($table)` resta invariata.
- Risultati:
  - con un solo schema, identici a oggi (verificato su 28 tabelle);
  - con più schemi, solo le colonne della relazione visibile, mai quelle di indici omonimi;
  - una tabella fuori dal `search_path` ha 0 colonne, e T13 lancia.

- [ ] **Step 1 — Test RED** (`PgsqlAdapterTest::test_introspection_follows_the_search_path`). Setup: `public.v147_t (id serial pk, name, price int)`, `s147.v147_t (code varchar pk, price varchar, extra text not null)` e un indice chiamato `v147_t` in un terzo schema.
  - `columns('v147_t')` restituisce esattamente `id`, `name`, `price` (oggi sono 8 colonne, con una pk composta fasulla).
  - Dopo `SET search_path TO s147, public`, con la cache spenta e `Table::clear_cache()`, restituisce `code`, `price`, `extra`.
  - Nel `finally`: `SET search_path TO DEFAULT`, perché la connessione è condivisa.
- [ ] **Step 2 — RED** su `mysql`: `PgsqlAdapterTest` gira in ogni suite. Conferma anche con `pgsql`.
- [ ] **Step 3 — Implementazione:** cambia solo il WHERE.
- [ ] **Step 4 — Esempio** (metà PG di `examples/sequences/`): con una tabella omonima in un altro schema, il modello vede solo le colonne di quella in `public`.
- [ ] **Step 5 — Commit:** `fix: introspect only the Postgres relation the search_path resolves (#147)`

**PR — Backward compatibility** (solo PG):
- Su un solo schema i risultati sono identici.
- Su più schemi spariscono le colonne fuse da altre tabelle o da indici omonimi.
- Una tabella fuori dal `search_path` non viene più introspettata da un altro schema.
- La cache dello schema non tiene conto di un `SET search_path` eseguito a runtime (preesistente).

**Release note:** `Postgres introspection reads only the relation the search_path resolves (to_regclass): same-named tables or indexes in other schemas no longer merge their columns into the model (#147)`

### Task 18 — #68: default PG estratti da `pg_get_expr` in PHP

**Issue:** #68 · **Branch:** `fix/68-pgsql-default-parsing` · **Decisione:** D15 · **Dipende da:** T17 · **Spec:** §4.5

**Files:**
- Modify: `lib/adapters/PgsqlAdapter.php`:
  - `query_column_info()`, con due nuove colonne nel risultato: `pg_get_expr(d.adbin, d.adrelid)` come `default_expr` e `a.attgenerated`. La colonna `default` resta, per le sottoclassi che sovrascrivono la query;
  - `create_column()` (124-132), che sostituisce `if ($column['default'])` con un controllo su null;
  - un nuovo parser privato.
- Modify: `test/PgsqlAdapterTest.php`.
- Example: `examples/sequences/sequences.php`, metà PG.

**Comportamento richiesto.** Il parser applica le regole nell'ordine. L'espressione grezza è nulla, oppure la colonna è generated (`attgenerated <> ''`) → nessun default. Altrimenti:

| Forma | Esito |
|---|---|
| `^nextval\('((?:[^']|'')+)'::regclass\)$` | sequence, per T19; nessun default |
| `^'((?:[^']|'')*)'(::[^']+)?$` | letterale: `''` → `'`, poi `Column::cast` |
| `^NULL(::[^']+)?$` | `null` |
| `^-?\d+(\.\d+)?([eE][-+]?\d+)?$`, `true`, `false` | cast |
| qualsiasi altra cosa | nessun default |

Si assume `standard_conforming_strings = on` (il default da PG 9.1); va scritto nel docblock.

- [ ] **Step 1 — Test RED** (`PgsqlAdapterTest::test_column_defaults_are_parsed_from_pg_get_expr`). Una tabella ad hoc con una colonna per caso; si confronta `Column::$default` con l'atteso:

  | DDL della colonna | Atteso | Oggi |
  |---|---|---|
  | `varchar(50) DEFAULT 'a::b'` | `'a::b'` | `"a'::character varying"` |
  | `varchar(50) DEFAULT 'it''s'` | `"it's"` | `"it''s"` |
  | `varchar(50) DEFAULT ''` | `''` | `null` |
  | `int DEFAULT 0` | `0` | `null` |
  | `text DEFAULT 'a'''` | `"a'"` | `"a''"` |
  | `text DEFAULT ''''` | `"'"` | `"''"` |
  | `varchar(50) DEFAULT upper('x')` | `null` | `"upper('x')"` |
  | `int DEFAULT (1+2)` | `null` | `0` |
  | `text[] DEFAULT '{}'` | `'{}'` | `"{}'[]"` |
  | enum `"V68Status"` `DEFAULT 'Active'` | `'Active'` | spazzatura |
  | enum `v68_status` `DEFAULT 'active'` | `'active'` | spazzatura |
  | `varchar(50) DEFAULT NULL` | `null` | `'NULL'` |
  | `int GENERATED ALWAYS AS (1) STORED` | `null` | espressione |

  Guardie, invariate rispetto a oggi:
  - `int DEFAULT -1` → `-1`;
  - `numeric DEFAULT 1.5` → `1.5`;
  - `boolean DEFAULT true` → `true`;
  - `date DEFAULT '2026-01-01'` → `ActiveRecord\DateTime`;
  - `timestamp DEFAULT now()` → `null`;
  - restano verdi `test_columns_default` e `test_boolean_column_introspection`.

  Più `test_new_model_saves_with_not_null_defaults`: su `cnt int NOT NULL DEFAULT 0, label varchar(10) NOT NULL DEFAULT ''`, il `save()` di un modello nuovo senza attributi riesce (oggi fallisce con 23502). Usa `STORED`: le colonne `VIRTUAL` esistono solo da PG 18.
- [ ] **Step 2 — RED** su `mysql` (classe PG) e su `pgsql`.
- [ ] **Step 3 — Implementazione:** le colonne aggiuntive nella query, il parser privato, il controllo su null.
- [ ] **Step 4 — Esempio** (metà PG di `examples/sequences/`): i default di un nuovo modello, con `DEFAULT 0` e un letterale con l'apice.
- [ ] **Step 5 — Commit:** `fix: parse Postgres column defaults from pg_get_expr in PHP (#68)`

**PR — Backward compatibility** (solo PG):
- `Column::$default` cambia solo nei casi oggi corrotti, e gli attributi dei nuovi record seguono. Per esempio, con `NOT NULL DEFAULT 0` un modello nuovo vale `0` invece di `null`:
  - `validates_numericality_of` senza `allow_null` ora passa sui nuovi record;
  - il JSON dei nuovi modelli mostra `0` o `''`.
- Le espressioni e le colonne generated non producono più un default.

**Release note:** `Postgres column defaults are parsed from pg_get_expr: DEFAULT 0/'' and quoted literals ('it''s', 'a::b') are kept, DEFAULT NULL is null, expressions and generated columns have no default — a new model on NOT NULL DEFAULT 0 no longer fails to save (#68)`

### Task 19 — #47: sequence reali e IDENTITY su PG

**Issue:** #47 · **Branch:** `fix/47-pgsql-real-sequences` · **Decisione:** D14 · **Dipende da:** T18, T6 · **Spec:** §4.5

**Files:**
- Modify: `lib/adapters/PgsqlAdapter.php`:
  - query di `query_column_info()`: aggiunge `a.attidentity` e la sequence propria della colonna;
  - `create_column()`;
  - `next_sequence_value()` (33-36).
- Modify: `lib/Column.php`: nuova proprietà `$identity`; `$sequence` (108-112) de-escapata.
- Modify: `lib/Table.php`, `set_sequence_name()` (757-769).
- Modify: `lib/Model.php`, `insert()` (956-1007): ramo IDENTITY ALWAYS.
- Modify: `lib/SQLBuilder.php` (insert, 242-256, 654-657), solo se serve per un INSERT senza la pk.
- Modify: `test/PgsqlAdapterTest.php` (più eventuali modelli in `test/models/`).
- Example: `examples/sequences/`, metà PG.

**Interfaces:**
- Produce `public ?string $identity = null;` su `Column`, con valori `null`, `'always'` o `'by default'`. È una proprietà pubblica nuova (additiva) ed è consumata da `Model::insert()`.
- Produce `Column::$sequence` popolata e de-escapata.
- T20 si appoggia alla resa qualificata delle sequence per i modelli `$db`.

**Comportamento richiesto:**
- **Sequence della colonna:**
  - colonne IDENTITY o con sequence owned: `pg_get_serial_sequence(c.oid::regclass::text, a.attname)`;
  - default `nextval` su una sequence non owned: la sequence citata dal default (`pg_depend`, oppure il parser di T18);
  - per le tabelle normali il nome è reso relativo al `search_path`, cioè lo stesso testo che `pg_get_expr` stampa oggi, quindi le tabelle SERIAL standard restano identiche byte per byte.
- **Precedenza in `set_sequence_name()`:**
  1. `static $sequence` dichiarata (`test_insert_with_no_sequence_defined` continua ad aspettarsi `DatabaseException`);
  2. `Column::$sequence` della pk;
  3. la convenzione;
  4. senza pk, `null` (#113).
- **IDENTITY ALWAYS con pk non impostata:** l'INSERT omette la colonna pk, poi la si legge con `insert_id(<sequence identity>)` (currval).
- **SERIAL e BY DEFAULT:** stessa forma di oggi (nextval esplicito), ma sulla sequence reale.
- **`next_sequence_value()`:** raddoppia gli apici. `nextval('a''b')` sostituisce `nextval('a\'b')`; l'output è identico per i nomi senza apici.

- [ ] **Step 1 — Test RED** in `PgsqlAdapterTest`, con DDL ad hoc. `create()` riesce e l'id si rilegge con `find()` in tutti questi casi:
  - tabella SERIAL rinominata dopo la creazione;
  - `id int GENERATED ALWAYS AS IDENTITY`;
  - IDENTITY BY DEFAULT rinominata;
  - nome di tabella con un apice;
  - tabella mixed-case `"V47Mixed"`;
  - default `nextval('v47_manual_numbers')` su una sequence non owned, senza dichiarazione.

  Più `next_sequence_value("a'b") === "nextval('a''b')"`.

  Guardie: `test_sequence_was_set`, `test_columns_sequence`, `test_next_sequence_value`, `test_insert_with_no_sequence_defined`, `RmBldgExplicitSequence`, `Ticket`.
- [ ] **Step 2 — RED** su `mysql` (classe PG) e `pgsql`. Errori attesi oggi: 42P01, 428C9, 42601.
- [ ] **Step 3 — Implementazione** come descritto sopra.
- [ ] **Step 4 — Esempio** (metà PG di `examples/sequences/`): tabella rinominata senza dichiarazione, `create()` su IDENTITY ALWAYS, tabella mixed-case. `Ticket` resta come demo della dichiarazione esplicita.
- [ ] **Step 5 — Commit:** `fix: use the column's own Postgres sequence and insert IDENTITY ALWAYS rows without the pk (#47)`

**PR — Backward compatibility** (solo PG):
- Per le tabelle SERIAL standard l'SQL resta byte-identico.
- Cambia solo dove oggi `create()` fallisce: tabelle rinominate, nomi mixed-case o con apici, IDENTITY ALWAYS, default non owned.
- `Column::$sequence` delle colonne IDENTITY passa da `null` al nome della sequence; quello dei nomi con apice viene de-escapato.
- Nuova proprietà `Column::$identity`.
- Le pk con default uuid restano fuori: #153 (T33).

**Release note:** `Postgres models use the column's real sequence (pg_get_serial_sequence / nextval default) instead of the {table}_{pk}_seq convention, and IDENTITY ALWAYS primary keys can be created; next_sequence_value() escapes quotes SQL-style (#47)`

### Task 20 — #143: modelli PG con `$db` e bytea come stringa binaria

**Issue:** #143 · **Branch:** `fix/143-pgsql-db-models` · **Decisione:** D16 · **Dipende da:** T19, T17 · **Spec:** §4.5

**Files:**
- Modify: `lib/adapters/PgsqlAdapter.php`: introspezione con schema esplicito. `query_column_info($table)` resta a un argomento; aggiungi un helper privato oppure `@internal`.
- Modify: `lib/Table.php`: in `get_meta_data()` (630-643; per PG oggi la riga 634 usa nomi non quotati), passa le parti dello schema e della tabella e mantieni la chiave di cache coerente.
- Modify: `lib/Column.php`: se il valore è una `resource`, `cast()` lo legge con `stream_get_contents()`.
- Modify: `test/PgsqlAdapterTest.php`, più i modelli in `test/models/`.
- Example: `examples/sequences/`, metà PG.

**Comportamento richiesto:**
- I modelli con `$db`, e quelli con `$table_name = '"schema".tabella'`, sono introspettati nel loro schema con `to_regclass('"schema"."tabella"')`, con le parti rese come le rende `quote_name()` (`Connection::split_name_parts`). Su questi modelli:
  - colonne e tipi coincidono con quelli della stessa DDL in `public`;
  - la pk viene inferita;
  - `Table::$sequence` è qualificata, dalla resa di T19;
  - `create`, `find`, `save` e `delete` funzionano, e la sequence di `public` non avanza.
- I valori `numeric` sono float come per i modelli normali (D12).
- I valori `bytea` diventano stringhe binarie per **tutti** i modelli PG, normali compresi; oggi sono `'Resource id #N'`.
- La firma di `query_column_info()` resta invariata: una sottoclasse che la sovrascrive con un solo parametro deve restare dichiarabile.

- [ ] **Step 1 — Test RED** in `PgsqlAdapterTest`. Setup: lo schema `s143`, preceduto da `DROP SCHEMA IF EXISTS s143 CASCADE`, e la stessa tabella in `public`. Verifiche:
  - il modello `$db` e quello pre-quotato hanno le stesse colonne e gli stessi tipi del modello normale;
  - pk inferita e sequence qualificata;
  - `create`/`find`/`save`/`delete` funzionano;
  - `nextval` di `public` è invariato;
  - variante mixed-case `"S143Mix"."MixTab"`;
  - un valore bytea si rilegge identico come stringa;
  - una sottoclasse di `PgsqlAdapter` che sovrascrive `query_column_info($table)` è dichiarabile.

  Come traccia di sola lettura, i test rimossi da #129 (`S26Book`, `S26MixTab`, commit `6f732b9`) mostrano anche le regressioni da evitare.
- [ ] **Step 2 — RED** su `mysql` (classe PG) e `pgsql`.
- [ ] **Step 3 — Implementazione** come descritto sopra. Se ora il ramo PG di `AdapterTest::test_partly_quoted_db_qualified_table_name_works` (~586) salva correttamente, togli l'eccezione che oggi salta il `save` su pg.
- [ ] **Step 4 — Esempio** (metà PG di `examples/sequences/`): un modello `$db` in uno schema proprio, accanto a una tabella omonima in `public`.
- [ ] **Step 5 — Commit:** `fix: introspect Postgres models in their own schema and read bytea as binary strings (#143)`

**PR — Backward compatibility** (solo PG):
- I modelli `$db` e `'"s".t'` diventano tipizzati e funzionanti:
  - i timestamp diventano `ActiveRecord\DateTime`;
  - i nuovi record ricevono i default;
  - il mass assignment di nomi che non sono colonne lancia `UndefinedPropertyException`.
- `bytea` passa da `'Resource id #N'` a una stringa binaria per tutti i modelli PG.

**Release note:** `Postgres models with $db (or a "schema".table name) are introspected in their own schema — typed attributes, inferred pk, working create() — and bytea values are read as binary strings instead of 'Resource id #N' (#143)`

### Task 21 — #92 (Cat 1): scritture e chiavi per nome reale della colonna

**Issue:** #92 (Refs) · **Branch:** `fix/92-real-column-names-in-writes` · **Decisione:** D13 · **Dipende da:** T19, T5 · **Spec:** §4.5

**Files:**
- Modify: `lib/Table.php`:
  - mappa costruita dopo `get_meta_data()`;
  - `insert()` (455-464), `update()` (585-599), `delete()` (605-615).
- Modify: `lib/Model.php`: chiavi di `find_by_pk()`/`pk_conditions()` (2169-2196, 2287-2292); ordine di default di `find('last')` (2124-2127).
- Modify/Create (test):
  - i 4 test di Cat 1 esistenti (oggi rossi su pg);
  - in `test/ActiveRecordWriteTest.php`, un test di scrittura su colonne con trattino e spazio;
  - la tabella `mixed_pk_items` (`"ItemID"` pk, `"Name"`) in tutti e tre i `test/sql/*.sql`, con il CSV e il modello.
- Example: `examples/attributes/attributes.php`, con la scrittura di una colonna con trattino o spazio su SQLite.

**Interfaces:**
- Produce `/** @internal */ public function column_name(string $attribute): string` su `Table`:
  - nome reale esatto → se stesso;
  - nome inflected → nome reale;
  - altrimenti → invariato.

**Comportamento richiesto:**
- **Dove si mappa:** INSERT, UPDATE (SET e WHERE) e DELETE usano il nome reale della colonna. La mappatura avviene **dopo** `process_data()`, così i valori legati restano identici.
- **Condizioni di pk e `last()`:** le condizioni di pk usano il nome reale. L'ordine di default di `last()` usa il nome reale, quotato solo se differisce dall'attributo.
- **SQL:**
  - per le colonne snake_case resta identico;
  - per le colonne mixed-case su MySQL e SQLite cambia solo la grafia;
  - le colonne con trattino o spazio, e le mixed-case su PG, passano da errore a funzionanti.
- **Invariati:** `SQLBuilder` e le chiavi degli hash `conditions` nei finder.

- [ ] **Step 1 — Test RED:**
  - `test_writes_hyphen_and_space_columns` (`ActiveRecordWriteTest`, tutti gli adapter): sulla tabella `rm-bldg`, con un modello senza validazioni o con `save(false)`, perché `RmBldg` ha validazioni incompatibili tra loro. Scrive `rm_name` e `space_out` (`"space out"` è `VARCHAR(1)`). Oggi è RED su mysql, mariadb, sqlite e pgsql.
  - `test_mixed_case_primary_key_round_trip`: su `MixedPkItem`, `create`, `find`, `last`, `save`, `delete`. Oggi è RED su pgsql.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`.
- [ ] **Step 3 — Implementazione** come descritto sopra.
- [ ] **Step 4 — Esempio:** scrittura e rilettura di una colonna con trattino su SQLite, con `last_sql`.
- [ ] **Step 5 — Commit:** `fix: write and key rows by the real column name, not the inflected attribute (#92)`

**PR — Backward compatibility:**
- L'SQL cambia solo per le colonne il cui nome reale differisce dall'attributo.
- Le colonne con trattino o spazio diventano scrivibili su tutti gli adapter; su PG funzionano le colonne e le pk mixed-case.
- Nessuna firma pubblica cambia (`column_name()` è `@internal`).

**Release note:** `Inserts, updates, deletes and primary-key lookups use the real column name: columns with hyphens/spaces become writable on every adapter, and mixed-case columns/primary keys work on Postgres (#92)`

### Task 22 — #92: suite pgsql in CI e minimo PostgreSQL 15

**Issue:** #92 (Closes) · **Branch:** `ci/92-pgsql-suite-in-ci` · **Decisione:** D13 · **Dipende da:** T16–T21 · **Spec:** §4.5

**Files:**
- Modify: `.github/workflows/ci.yml`: nuovo step dopo `Tests (sqlite)` nel job `test`.
- Modify: `README.md`: versioni supportate (~42-45), con il minimo PostgreSQL 15. La frase sulla "full test suite" diventa vera.
- Example: non si applica.

**Comportamento richiesto:**
- Lo step pgsql gira in tutte e 12 le celle della matrice (PHP 8.3/8.4/8.5 × postgres 18/15/16/17), senza coverage:
  ```yaml
        - name: Tests (pgsql)
          run: PHPAR_CONNECTION=pgsql composer run test
  ```

- [ ] **Step 1 — Verifica locale:**
  - `$RUN $WT pgsql` → OK, con 0 errori, 0 failure e 0 skip;
  - `PHPAR_IMAGE=php-activerecord-tests:8.5 $RUN $WT pgsql` → OK.
  - Facoltativo: suite contro `postgres:15` in un container temporaneo sulla rete `php-activerecord_default`. In alternativa ci si affida alla cella `min` della CI della PR.
- [ ] **Step 2 — Modifica** `ci.yml` e il README.
- [ ] **Step 3 — CI della PR:** tutte le celle sono verdi, step pgsql compreso.
- [ ] **Step 4 — Commit:** `ci: run the full suite on Postgres in every matrix cell (#92)`

**PR — Backward compatibility:** nessuna a runtime. Si dichiara il minimo PostgreSQL 15. **Release note:** `CI runs the full test suite on PostgreSQL 15–18; PostgreSQL 15 is the minimum supported version (#92)`

---

## Ondata 6 — Alias, eager load, serializzazione

### Task 23 — #144: alias mappati quando la colonna non esiste ("colonna viva")

**Issue:** #144 · **Branch:** `fix/144-alias-live-column-rule` · **Decisione:** D18 · **Dipende da:** T1, T4, T21 · **Spec:** §4.6

**Files:**
- Modify: `lib/Connection.php`: nuovo controllo dal vivo.
- Modify: `lib/adapters/PgsqlAdapter.php`: override del controllo via catalogo.
- Modify: `lib/Table.php`: memoria positiva, `condition_alias_map()`, e azzeramento della memoria in `clear_cache()`.
- Modify: `lib/Model.php`:
  - `finder_conditions_from_args()`, per count/exists;
  - `update_all()` (`set` e `conditions`) e `delete_all()`, sull'hash normalizzato da `bulk_write_options()` (T1).
- Modify: `lib/Relationship.php`, `to_positional_conditions()` (618-627): usa tabella e connessione del modello target; non si applica a `through`.
- Modify/Create (test):
  - `test/helpers/AdapterTest.php`;
  - modelli `test/models/{MarqueeEvent,ShadowAliasVenue,ShadowAliasEvent}.php`, ripresi come traccia dai commit `5c89cbe` e `6f732b9` di #129;
  - una tabella con una colonna generated omonima di un alias, in tutti e tre i `test/sql/*.sql`, con un CSV che **non** include la colonna generated.
- Modify: `README.md`, "Hash conditions".
- Example: `examples/attributes/attributes.php`.

**Interfaces:**
- Consuma `bulk_write_options()` (T1) e `finder_conditions_from_args()` (T4).
- Produce `/** @internal */ public function column_exists(string $table, string $column): bool` su `Connection`:
  - MySQL, MariaDB e SQLite: `SELECT <quote_name($column)> FROM <tabella completamente qualificata> LIMIT 0` con `catch (DatabaseException)`; su MySQL e SQLite un errore non rompe la transazione.
  - `PgsqlAdapter`: `SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(?) AND attname = ? AND NOT attisdropped`. Non lancia mai, quindi non abortisce la transazione.

**Comportamento richiesto:**
- **Percorsi coperti:** count, exists, update_all (`set` e `conditions`), delete_all, condizioni hash delle relazioni.
- **Regola per ogni chiave alias presente nell'hash.** È "colonna", e la chiave resta com'è (come oggi), se:
  - corrisponde esattamente a una colonna in cache; oppure
  - c'è una risposta positiva memorizzata sulla Table; oppure
  - `column_exists()` restituisce true. Solo questa risposta positiva si memorizza; quelle negative mai.

  In tutti gli altri casi la chiave viene mappata sulla colonna dell'alias.
- **Esclusioni:** non si mappa con l'opzione `from` in count/exists, né nelle condizioni `through`.
- **Alias e colonna insieme:** restano entrambi, in AND, come nei finder (regola A/E di #129).
- **Invariati:** `find`, `all`, `first`, `last` e i finder dinamici, dove l'alias vince sempre.
- **README:** la differenza per gli alias omonimi di una colonna va documentata nel README. Testo:
  > An `$alias_attribute` name is replaced by its column. In `find`/`all`/`first`/`last` and dynamic finders this always happens, even when the table also has a column with the alias's name. In `count`, `exists`, `update_all`, `delete_all` and relationship `conditions` hashes it happens only when the database confirms the table has no such column, so a real column still wins there, as before. An alias and its column are both kept and ANDed.

- [ ] **Step 1 — Test RED** in `AdapterTest`, quindi su tutti gli adapter:
  - `test_alias_keys_are_mapped_in_count_exists_update_all_and_delete_all`: Venue `marquee` → `name`, anche nello `set` di `update_all`. Oggi lancia `DatabaseException`.
  - `test_alias_keys_are_mapped_in_relationship_hash_conditions`: `MarqueeEvent`, lazy ed eager, inclusa la regola E.
  - `test_alias_mapping_inside_a_transaction_keeps_it_usable`: dopo la mappatura dentro `Model::transaction()`, una write successiva e il commit riescono, anche su PG.
  - Guardie, da tenere verdi:
    - `test_alias_named_like_a_column_is_not_mapped` (`ShadowAliasVenue`/`ShadowAliasEvent`): la colonna vince come oggi.
    - `test_column_added_after_the_table_was_loaded_still_wins`: su una tabella dedicata creata e droppata nel test, carica la Table, fai `ALTER TABLE … ADD status`; count, update_all e delete_all colpiscono la colonna reale. È la Review Focus 2.
    - `test_generated_column_named_like_an_alias_still_wins`: tabella dedicata con colonna generated (`AS (…) VIRTUAL` su MySQL e MariaDB, `GENERATED ALWAYS AS (…) STORED` su PG, `GENERATED ALWAYS AS (…) VIRTUAL` su SQLite).
    - `test_alias_differing_only_in_case_from_a_column_is_not_remapped`: su MySQL, MariaDB e SQLite (identificatori case-insensitive); su PG vale la risposta del catalogo, che distingue le maiuscole. È la Review Focus 1.
- [ ] **Step 2 — RED** su `mysql` (AdapterTest gira su tutti e quattro gli adapter), `sqlite`, `pgsql`.
- [ ] **Step 3 — Implementazione** come descritto sopra. Riusa la struttura e i test di G (#129), sostituendo il controllo sullo schema in cache con `column_exists()`.
- [ ] **Step 4 — Esempio** (`examples/attributes/attributes.php`): `Member` con `email_address` → `email` in count, exists, update_all (set e conditions) e delete_all, con `last_sql`. Più un modello con un alias omonimo, che mostra che la colonna reale vince.
- [ ] **Step 5 — Commit:** `fix: map alias_attribute keys in count, exists, bulk writes and relationship conditions when the column does not exist (#144)`

**PR — Backward compatibility:**
- Le chiamate che oggi falliscono con "unknown column <alias>" ora girano mappate. Se il target dell'alias non è una colonna (nel monolite ci sono alias verso relazioni), l'errore ora nomina il target.
- Le query che funzionano oggi restano identiche: stesso SQL, stesse righe.
- Per una chiave alias che non è in cache parte una query di verifica in più, visibile nei log.
- Monolite: 22 alias, nessuno usato in questi percorsi.

**Release note:** `$alias_attribute names are mapped in count/exists/update_all/delete_all and relationship conditions when the table has no column of that name (checked against the live database); a real column still wins (#144)`

### Task 24 — #137: `limit`/`offset` dichiarati applicati per owner in SQL

**Issue:** #137 · **Branch:** `perf/137-eager-limit-window` · **Decisione:** D19 · **Dipende da:** T5 · **Spec:** §4.6

**Files:**
- Modify: `lib/Relationship.php`: percorso eager (189-361) e nuovi helper privati.
- Modify: `lib/Table.php`: idratazione `@internal` che condivide il ciclo di `find_by_sql()` (325-354). `find_by_sql()` pubblico resta invariato.
- Modify: `lib/Connection.php`: nuovo `supports_window_functions()`.
- Modify: `lib/adapters/SqliteAdapter.php`: override di `supports_window_functions()`.
- Modify: `test/RelationshipEagerLazyParityTest.php`.
- Example: `examples/relationships/relationships.php`, più `examples/README.md`.

**Interfaces:**
- Produce `public function supports_window_functions(): bool` su `Connection`, che restituisce `true`. È un metodo pubblico nuovo (additivo). `SqliteAdapter` restituisce `version_compare($this->connection->getAttribute(PDO::ATTR_SERVER_VERSION), '3.25.0', '>=')`.

**Comportamento richiesto:**
- **Quando si usa la finestra:** solo se valgono tutte le condizioni seguenti; altrimenti si resta sul percorso di oggi, con SQL byte-identico.
  - la relazione è HasMany, non HasOne;
  - `eager_window()` riporta `skip > 0` oppure `take !== null`;
  - il ritorno anticipato per `limit 0` resta com'è;
  - non sono dichiarati `select`, `group` né `having`;
  - l'ordine dichiarato non contiene ordinali (`/(^|,)\s*\d+\s*(asc|desc)?\s*(,|$)/i`);
  - `supports_window_functions()` è true;
  - la tabella figlia non ha una colonna `ar_rn`;
  - su MySQL e MariaDB nessuna chiave di partizione è testuale (Review Focus 1).
- **SQL.** I nomi sono quotati con `quote_name()` e i numeri inseriti con `intval()`:
  ```sql
  SELECT * FROM (
    SELECT <child>.* [, through: <middle>.<key> AS match_key],
           ROW_NUMBER() OVER (PARTITION BY <colonne di partizione> ORDER BY <ordine dichiarato>[, <pk del figlio>]) AS ar_rn
    FROM <child> [<join through come oggi>] WHERE <condizioni eager di oggi>
  ) ar_w WHERE ar_rn > <skip> [AND ar_rn <= <skip + take>] ORDER BY ar_rn
  ```
- **Colonne di partizione:** la fk; con chiavi composte, tutte le `$query_keys` qualificate; con `through`, `$qualified_keys[$query_key]` (righe ~235 e ~257).
- **Tie-breaker:** l'ordine dichiarato più la pk del figlio; la sola pk se non c'è un ordine; nessuno se la tabella non ha pk.
- **Idratazione:**
  - `unset($row['ar_rn'])` prima di `new Model($row, false, true, false)`;
  - le include annidate girano solo sui modelli tenuti;
  - l'attach usa `eager_key_matcher` con `skip` = 0 e `take` come tetto per owner.
- **has_one:** invariato.

- [ ] **Step 1 — Test RED** in `RelationshipEagerLazyParityTest`:
  - `test_eager_limited_has_many_hydrates_only_kept_children`: un modello dedicato su `events` con un contatore in `after_construct`, più eventi creati nel test.
  - `test_nested_include_under_a_limited_has_many_queries_only_kept_ids`: conta i `?` nel `last_sql` della tabella annidata.
  - `test_eager_limit_uses_row_number`: l'SQL contiene `ROW_NUMBER() OVER (PARTITION BY`.

  Nuovi test di parità tra eager e lazy (da tenere verdi):
  - `through` con tabella di join più limit;
  - `through` con fk inversa più limit;
  - chiavi composte più limit;
  - parità con pareggi, grazie al tie-breaker;
  - con `select` o `group` dichiarati l'SQL non contiene `ROW_NUMBER`;
  - nessun `ar_rn` in `attributes()`, `to_array()` e `to_json()`;
  - chiavi testuali su MySQL: si resta sul percorso di oggi.

  Il predicato di versione di SQLite si testa direttamente, senza skip.

  Guardie: `test_eager_limit_applies_per_owner` (il suo `assert_sql_doesnt_has('LIMIT')` resta vero), i test offset-only, limit-zero, finestra e has_one-ignora, i tre test con chiavi stringa.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`.
- [ ] **Step 3 — Implementazione** come descritto sopra. Nel report riesegui l'harness old-vs-new di #133: cambia l'SQL solo per le has_many limitate, e i risultati restano identici in assenza di pareggi.
- [ ] **Step 4 — Esempio:** estendi la sezione `PostBySameAuthor::latest_same_author_posts` (limit 1). Stampa l'SQL `ROW_NUMBER()` e le righe lette rispetto a quelle tenute.
- [ ] **Step 5 — Commit:** `perf: apply a declared has_many limit/offset per owner in SQL with ROW_NUMBER() (#137)`

**PR — Backward compatibility:**
- Cambia l'SQL emesso dagli eager include di has_many con `limit` o `offset` dichiarati.
- Si costruiscono meno modelli, quindi `after_construct` gira solo sui figli tenuti, e le include annidate leggono meno righe.
- Con pareggi l'ordine diventa deterministico.
- Le altre has_many restano byte-identiche.
- Nuovo metodo pubblico `Connection::supports_window_functions()`.
- Monolite: una sola relazione limitata, mai caricata in eager.

**Release note:** `Eager includes of a has_many with a declared limit/offset fetch only each owner's window (ROW_NUMBER()) instead of every child; falls back to the previous behaviour for text keys on MySQL/MariaDB, declared select/group/having, ordinal ORDER BY and SQLite < 3.25 (#137)`

### Task 25 — #124: `to_csv` rifiuta valori annidati

**Issue:** #124 · **Branch:** `fix/124-to-csv-rejects-nested-values` · **Decisione:** D21 · **Dipende da:** — · **Spec:** §4.6

**Files:**
- Modify: `lib/Serialization.php`: `CsvSerializer` (408-462); alla riga 425 sostituisci `@$this->options['only_header']` con `!empty($this->options['only_header'])`.
- Modify: `lib/Model.php`: docblock di `to_csv()` (2348-2371).
- Modify: `test/SerializationTest.php`.
- Example: `examples/serialization/serialization.php`, più la cella in `examples/README.md` (oggi elenca solo `to_json`/`to_xml`/`to_array`).

**Comportamento richiesto:**
- `to_csv(['include' => …])`, e qualunque valore di `methods`/`only_method` che sia un array, lanciano `ActiveRecordException("to_csv() cannot serialize nested values (include, or methods returning arrays): use to_json() or to_array()")`.
- Ogni altro output di `to_csv` resta invariato.
- Non viene più emesso nessun warning, neanche soppresso.

- [ ] **Step 1 — Test RED** in `SerializationTest`:
  - `Book::find(1)->to_csv(['include' => 'author'])` → eccezione;
  - `Author::find(1)->to_csv(['include' => 'books'])` → eccezione;
  - un metodo che restituisce un array, passato in `methods` → eccezione;
  - `test_to_csv_raises_no_suppressed_warning`: installa un error handler che registra ogni errore indipendentemente da `error_reporting()`; un `to_csv()` semplice non registra nulla. Ripristina l'handler in `finally`.

  Guardie: i test CSV esistenti. Ripristina le statiche di `CsvSerializer`, come fa già la classe (#78).
- [ ] **Step 2 — RED** su `mysql`, `sqlite`.
- [ ] **Step 3 — Implementazione** come descritto sopra.
- [ ] **Step 4 — Esempio:** una sezione `to_csv` con riga semplice, `only_header` e il messaggio dell'eccezione catturata.
- [ ] **Step 5 — Commit:** `fix: reject nested values in to_csv() instead of writing "Array" (#124)`

**PR — Backward compatibility:** chi oggi chiama `to_csv` con `include` o con `methods` che restituiscono array riceve un'eccezione invece di una cella "Array" e di un warning. Monolite: nessun `to_csv`.

**Release note:** `**BREAKING (behavior):** to_csv() throws ActiveRecordException for include and for methods returning arrays instead of writing "Array" with a warning (#124)`

### Task 26 — #142: won't-fix documentato e test di guardia

**Issue:** #142 (chiusa come "not planned") · **Branch:** `docs/142-hash-keys-checked-by-the-database` · **Decisione:** D17 · **Dipende da:** T23 (stessa sezione del README) · **Spec:** §4.6

**Files:**
- Modify: `README.md`, "Hash conditions".
- Modify:
  - `test/helpers/AdapterTest.php`: chiave su colonna generated, tabelle dedicate create e droppate nel test;
  - `test/PgsqlAdapterTest.php`: `ctid`;
  - `test/MariadbAdapterTest.php`: `row_end` di una tabella `WITH SYSTEM VERSIONING`;
  - `test/SqliteAdapterTest.php`: colonna nascosta FTS5. La SQLite della CI ha FTS5, quindi il test asserisce senza skip.
- Example: non si applica (documentazione).

**Comportamento richiesto:**
- Nessun cambio a runtime.
- Frase da aggiungere al README:
  > Hash-condition keys are not checked against the model's columns: an unknown key is rejected by the database with `DatabaseException` (MySQL 42S22, Postgres 42703, SQLite "no such column") before any row is touched, and columns that introspection does not list — generated or invisible columns, MySQL `my_row_id`/`_rowid`, MariaDB `row_start`/`row_end`, Postgres `ctid`/`xmin`, SQLite `rowid` and FTS5 hidden columns, a column added after the model was loaded — work as keys.

- [ ] **Step 1 — Test di guardia**, verdi già su master: con quelle chiavi `count`, `update_all` e `delete_all` funzionano e colpiscono le righe attese.
- [ ] **Step 2 — Verifica** su `mysql`, `mariadb`, `sqlite`, `pgsql`: tutti verdi.
- [ ] **Step 3 — Commit:** `docs: state that hash-condition keys are checked by the database, and pin hidden-column keys (#142)`
- [ ] **Step 4 — Dopo il merge dell'ondata 6 su master:** il controller chiude #142 come "not planned", con un commento che rimanda al testo del README e ai test. La PR usa `Refs #142`, non `Closes`.

**PR — Backward compatibility:** nessuna. **Release note:** `Docs: hash-condition keys are validated by the database; hidden/generated/system columns keep working as keys (#142, won't fix)`

---

## Ondata 7 — Inflector e tooling

### Task 27 — #48: inflector, lista curata di eccezioni

**Issue:** #48 · **Branch:** `fix/48-inflector-irregular-exceptions` · **Decisione:** D23 · **Dipende da:** — · **Spec:** §4.7

**Files:**
- Modify: `lib/Utils.php`: tabelle degli irregolari (393-402) e i cicli di `pluralize()` (421-445; 429-435) e `singularize()` (451-475; 459-465).
- Modify: `test/InflectorTest.php`, `test/UtilsTest.php`.
- Example: `examples/simple/simple.php` e `simple.sql`: una classe `Human` sulla tabella `humans`.

**Comportamento richiesto:**
- **Plurali:** override valutati prima degli irregolari generici, con semantica di suffisso come gli `irregular` di Rails, quindi valgono anche per `superhuman`, `player_human` e simili.

  | Singolare | Plurale |
  |---|---|
  | human | humans |
  | german | germans |
  | roman | romans |
  | ottoman | ottomans |
  | shaman | shamans |
  | talisman | talismans |
  | caiman | caimans |
  | cayman | caymans |
  | doberman | dobermans |
  | walkman | walkmans |
  | mongoose | mongooses |

- **Singolari:** una guardia con confine non-lettera `(?<![a-z])`, senza distinzione di maiuscole, lascia invariate queste parole: abdomen, acumen, albumen, bitumen, cerumen, dolmen, foramen, gravamen, hymen, lumen, omen, regimen, rumen, semen, specimen, stamen, yemen.
- **Fuori da questo task:** la regola `ex$` (#151, T31) e il `\b` proposto nell'issue, che è scartato.

- [ ] **Step 1 — Test RED.**
  - `tableize`: `Human` → `humans`, `PlayerHuman` → `player_humans`, `Ottoman` → `ottomans`, `Mongoose` → `mongooses`.
  - `singularize`: `abdomen`, `specimen`, `acumen`, `omen`, `yemen` restano invariati.
  - `classify('specimen', true) === 'Specimen'`.

  Guardie (verdi oggi):
  - `tableize`: `Woman` → `women`, `Fireman` → `firemen`, `FireMan` → `fire_men`, `SalesPerson` → `sales_people`, `AngryPerson` → `angry_people`, `GrandChild` → `grand_children`.
  - `singularize`: `women` → `woman`, `horsemen` → `horseman`, `firemen` → `fireman`, `people` → `person`.

  Estendi allo stesso modo le liste di `UtilsTest`.
- [ ] **Step 2 — RED** su `mysql`: sono funzioni pure, una suite basta.
- [ ] **Step 3 — Implementazione** come descritto sopra.
- [ ] **Step 4 — Esempio:** `Human::table_name()` → `humans`.
- [ ] **Step 5 — Commit:** `fix: keep human/german/ottoman/… regular and abdomen/specimen/… singular in the inflector (#48)`

**PR — Backward compatibility:**
- Il nome della tabella cambia solo per i modelli che hanno uno dei nomi elencati e non dichiarano `$table_name` (esempio: `Human`, da `humen` a `humans`).
- I singolari cambiano solo dove oggi l'output non è valido (`Speciman`, `Abdoman`).
- Monolite: 0 differenze su tutti i nomi di modello e di associazione.

**Release note:** `**BREAKING (behavior):** the inflector keeps human/german/roman/ottoman/shaman/talisman/caiman/cayman/doberman/walkman/mongoose regular (Human → humans, not humen) and no longer singularizes abdomen/specimen/acumen/… — models named after these words without $table_name map to a different table (#48)`

### Task 28 — #125: stub PHPStan tramite `extension.neon`

**Issue:** #125 · **Branch:** `fix/125-phpstan-extension-neon` · **Decisione:** D22 · **Dipende da:** T22 (`ci.yml`) · **Spec:** §4.7

**Files:**
- Create: `extension.neon` nella root del pacchetto.
- Modify: `composer.json`: `extra.phpstan.includes` → `["extension.neon"]`; `"type"` resta `"library"`.
- Modify: `README.md`: nuova sezione "Static analysis".
- Create: `test/phpstan-consumer/`, cioè `composer.json` (repository `path` verso il checkout), `phpstan.neon` (livello 8) e `src/models.php`. Quest'ultimo contiene un `has_many` in forma di array, un `belongs_to` in forma di nome semplice e un `dumpType`.
- Modify: `.github/workflows/ci.yml`:
  - nuovo job `phpstan-consumer`: copia il fixture in una cartella temporanea, `composer install` con `phpstan/phpstan`, `phpstan/extension-installer` e `allow-plugins`, poi `vendor/bin/phpstan analyse`, che deve finire con exit 0;
  - più `composer validate`.
- Example: non si applica (tooling).

**Comportamento richiesto:**
- `extension.neon`:
  ```neon
  parameters:
      stubFiles:
          - stubs/relationship-properties.stub
  ```
- Effetto per i consumer:
  - con l'installer, PHPStan carica lo stub automaticamente;
  - senza installer, il README indica `includes: [vendor/ristocloud-group/php-activerecord/extension.neon]`.
- Le PHPDoc autosufficienti sono fuori da questo task: #156 (T35).

- [ ] **Step 1 — RED:** il job consumer sul codice di BASE fallisce con `Error while loading …/stubs/relationship-properties.stub: Unexpected 'namespace ActiveRecord;'` (exit 1). Verificalo in locale con PHP e composer dell'host, come nella verifica (spec §4.7).
- [ ] **Step 2 — Implementazione:** `extension.neon`, `composer.json`, README e il job CI.
- [ ] **Step 3 — GREEN:** in locale, il consumer finisce con exit 0 e 0 errori; nella CI della PR il job è verde; `composer validate` è OK; `$RUN $WT gate` dà PASS (l'analisi del repo non cambia).
- [ ] **Step 4 — Commit:** `fix: ship the relationship stub through extension.neon so consumers' PHPStan loads it (#125)`

**PR — Backward compatibility:** nessuna a runtime. Chi usa l'installer passa da "PHPStan va in abort" a "stub applicato", e la cosa potrebbe far emergere errori veri. Chi aveva aggiunto lo stub a mano lo carica due volte, il che è innocuo.

**Release note:** `PHPStan: the relationship stub is shipped through extension.neon (picked up automatically by phpstan/extension-installer, which previously aborted on the .stub); see "Static analysis" in the README (#125)`

---

## Ondata 8 — Nuove issue (#149–#156, con gate BC)

Ogni task di questa ondata parte da un **gate**: prima di creare il branch, il controller pone al maintainer la domanda indicata (AskUserQuestion, opzione raccomandata per prima) e registra la risposta nel ledger e nel corpo della PR. Il "Comportamento richiesto" descrive l'opzione raccomandata. Se il maintainer ne sceglie un'altra, il controller adatta il task nel brief prima di lanciare l'implementer. Le prove e i repro verificati sono nella issue GitHub e nella spec, §5.

### Task 29 — #149: `find($pk, conditions)` mette in AND pk e condizioni

**Issue:** #149 · **Branch:** `fix/149-pk-and-conditions` · **Dipende da:** T4, T5, T23 · **Spec:** §5

**Gate G1** — Cosa fare quando a un finder si passano una pk e delle conditions?
- **(A, raccomandata)** AND di entrambe in `find`/`first`/`last`/`count`/`exists`, come Rails `where(c).find(id)`.
- (B) `ActiveRecordException` se arrivano entrambe.
- (C) Solo documentazione.

**Files:**
- Modify: `lib/Model.php`: `find()` (~2147, `if ($num_args > 0 && !isset($options['conditions']))`), `find_by_pk()` e `finder_conditions_from_args()`.
- Create: `test/FindPkWithConditionsTest.php`.
- Example: `examples/finders/finders.php`.

**Comportamento richiesto (A)** — tabella `v149_books (id, name)` con le righe `(1,'a')`, `(2,'b')`, `(3,'c')`, creata nel test. In alternativa si possono usare le fixture `authors`.

| Chiamata | Oggi | Dopo |
|---|---|---|
| `find(1, ['conditions' => ['name' => 'b']])` | id 2 | `RecordNotFound` |
| `find(2, ['conditions' => ['name' => 'b']])` | id 2 | id 2 |
| `find(1, ['name' => 'b'])` (hash nudo) | id 2 | `RecordNotFound` |
| `find(1, 2, ['conditions' => ['name' => 'b']])` | lista | `RecordNotFound` ("found 1, but was looking for 2") |
| `count(1, ['name' => 'b'])` | 1 | 0 |
| `exists(1, ['name' => 'b'])` | true | false |

L'SQL è del tipo `WHERE (<pk> = ?) AND (<conditions>)`: le condizioni dell'utente vanno tra parentesi e messe in AND, come fa #120 per le condizioni dichiarate delle relazioni. Il caso senza pk resta invariato.

- [ ] **Step 1 — Test RED:** un test per ogni riga della tabella, più le guardie `find(1)`, `find('all', ['conditions' => …])` e `count(['conditions' => …])`, invariate.
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`.
- [ ] **Step 3 — Implementazione** secondo l'opzione scelta.
- [ ] **Step 4 — Esempio:** `find($id, ['conditions' => ['owner_id' => …]])` come scoping.
- [ ] **Step 5 — Commit:** `fix: AND the primary key with the conditions in find(), count() and exists() (#149)`

**PR — Backward compatibility:** `find($pk, conditions)` smette di restituire un record diverso. Nel corpo della PR va la nota di sicurezza: oggi lo scoping per proprietario è aggirabile. Monolite: 0 chiamate.

**Release note:** `**BREAKING (behavior):** find()/first()/last() with a primary key and conditions AND both (they returned another record matching the conditions); count()/exists() no longer drop the conditions (#149)`

### Task 30 — #150: la condizione stringa `'0'` non viene più scartata

**Issue:** #150 · **Branch:** `fix/150-zero-string-condition` · **Dipende da:** T1 · **Spec:** §5

**Gate G2** — Come trattare la condizione stringa `'0'`?
- **(A, raccomandata)** È un frammento SQL come gli altri: `WHERE 0`, cioè nessuna riga su MySQL e SQLite e `DatabaseException` su PG, dove non è un booleano. Solo `null`, `''` e `[]` significano "nessuna condizione".
- (B) Trattarla come "nessuna condizione", come oggi, ma documentarlo.
- (C) `ActiveRecordException` per `'0'`.

**Files:**
- Modify: `lib/SQLBuilder.php`: `build_select()` (~695), `build_delete()` (~627) e `build_update()` (~732), dove oggi c'è `if ($this->where)`.
- Create: `test/ZeroStringConditionTest.php`.
- Example: `examples/conditions/conditions.php`.

**Comportamento richiesto (A):**
- Con `['conditions' => '0']`, `all`, `count`, `delete_all` e `update_all` emettono `WHERE 0`: su MySQL e SQLite non restituiscono e non toccano nessuna riga; su PG lanciano `DatabaseException`.
- Il test di "vuoto" diventa `$this->where !== null && $this->where !== ''`.

- [ ] **Step 1 — Test RED:** le quattro chiamate su una tabella con 3 righe. Su MySQL e SQLite si attende 0 righe e nulla di modificato; su PG si attende un'eccezione (test adapter-aware, senza skip).
- [ ] **Step 2 — RED** su `mysql`, `sqlite`, `pgsql`.
- [ ] **Step 3 — Implementazione:** cambia il controllo di "vuoto" nei tre punti.
- [ ] **Step 4 — Esempio:** `all(['conditions' => '0'])` → 0 righe.
- [ ] **Step 5 — Commit:** `fix: keep a '0' condition instead of dropping the WHERE clause (#150)`

**PR — Backward compatibility:** `'0'` smette di significare "tutte le righe" in finder e bulk; su PG diventa un errore del database.

**Release note:** `**BREAKING (behavior):** a '0' string condition is kept as WHERE 0 instead of being dropped (which selected, updated or deleted every row) (#150)`

### Task 31 — #151: regola plurale `-ex`/`-ix`

**Issue:** #151 · **Branch:** `fix/151-inflector-ex-plural` · **Dipende da:** T27 · **Spec:** §5

**Gate G3** — Correggere la regola sapendo che cambia il nome della tabella dei modelli senza `$table_name`?
- **(A, raccomandata)** Sì, con la regola di Rails `/(matr|vert|ind)(?:ix|ex)$/i`.
- (B) No: documentare e dichiarare `$table_name`.

**Files:**
- Modify: `lib/Utils.php:341` (`$plural`).
- Modify: `test/InflectorTest.php`, `test/UtilsTest.php`.
- Example: `examples/simple/simple.php`.

**Comportamento richiesto (A):**

| Chiamata | Oggi | Dopo |
|---|---|---|
| `pluralize('complex')` | `complices` | `complexes` |
| `pluralize('annex')` | `annices` | `annexes` |
| `pluralize('mutex')` | `mutices` | `mutexes` |
| `tableize('MatrixRow')` | `matrices_row` | `matrix_rows` |

Restano invariati: `index` → `indices`, `vertex` → `vertices`, `matrix` → `matrices`.

- [ ] **Step 1 — Test RED** con la tabella sopra e le guardie.
- [ ] **Step 2 — RED** su `mysql`.
- [ ] **Step 3 — Implementazione:** una riga.
- [ ] **Step 4 — Esempio:** `Complex::table_name()`.
- [ ] **Step 5 — Commit:** `fix: anchor the -ix/-ex plural rule like Rails (#151)`

**PR — Backward compatibility:** cambia il nome della tabella per i modelli senza `$table_name` il cui nome termina in "ex", o che contengono matr/vert/ind + ix seguiti da altro. Monolite: nessun nome toccato.

**Release note:** `**BREAKING (behavior):** the -ex plural rule is anchored (complex → complexes, MatrixRow → matrix_rows); models named like this without $table_name map to a different table (#151)`

### Task 32 — #152: colonne `REAL` come float

**Issue:** #152 · **Branch:** `fix/152-real-columns-float` · **Dipende da:** — · **Spec:** §5

**Gate G4** — Tipizzare le colonne `REAL` come float su SQLite e PG?
- **(A, raccomandata)** Sì, come `float` e `double`.
- (B) No: documentare.

**Files:**
- Modify: `lib/Column.php`, `$TYPE_MAPPING` (~42-46): aggiungi `'real'`.
- Modify: `test/SqliteAdapterTest.php`, `test/PgsqlAdapterTest.php`, con tabelle ad hoc.
- Example: `examples/attributes/attributes.php`.

**Comportamento richiesto (A):** su SQLite e PG una colonna `REAL` ha lo stesso tipo di `float`/`double`, e il valore letto è un float. MySQL resta invariato (il suo `REAL` è già `DOUBLE`).

- [ ] **Step 1 — Test RED:** `columns(<t>)['r']->type === Column::DECIMAL`, e il valore letto è un `float`.
- [ ] **Step 2 — RED** su `mysql` (le classi adapter girano in ogni suite).
- [ ] **Step 3 — Implementazione:** una voce nella mappa dei tipi.
- [ ] **Step 4 — Esempio:** una colonna `REAL` letta come float.
- [ ] **Step 5 — Commit:** `fix: type REAL columns like float/double on SQLite and Postgres (#152)`

**PR — Backward compatibility:** sugli attributi `REAL` di SQLite e PG il tipo passa da stringa a float. Tocca anche la suite di test SQLite del monolite, che ha `quantita REAL`.

**Release note:** `**BREAKING (behavior):** REAL columns are typed as float on SQLite and Postgres (they were strings such as '1.2345679e+09') (#152)`

### Task 33 — #153: pk PG con default non-sequence (uuid)

**Issue:** #153 · **Branch:** `fix/153-pgsql-non-sequence-pk-default` · **Dipende da:** T19, T18 · **Spec:** §5

**Gate G5** — Come creare un record la cui pk ha un default diverso da `nextval()`?
- **(A, raccomandata)** Nessuna sequence: l'INSERT omette la pk e la rilegge con `INSERT … RETURNING <pk>`, solo su PG e solo quando la pk non è impostata.
- (B) Richiedere una pk esplicita e documentarlo.
- (C) `RETURNING` per ogni insert PG.

**Files:**
- Modify: `lib/Table.php` (`set_sequence_name()`), `lib/Model.php` (`insert()`), `lib/SQLBuilder.php` (`insert` con `RETURNING`), `lib/adapters/PgsqlAdapter.php`.
- Modify: `test/PgsqlAdapterTest.php`.
- Example: `examples/sequences/`, metà PG.

**Comportamento richiesto (A):**
- Per una pk con `DEFAULT gen_random_uuid()`, `Table::$sequence` è `null` e il default non diventa un attributo (lo garantisce già T18).
- `create(['name' => 'x'])` restituisce un modello con la pk generata dal DB.
- SERIAL e IDENTITY restano invariati, come da T19.

- [ ] **Step 1 — Test RED:** `create()` su `id uuid PRIMARY KEY DEFAULT gen_random_uuid()` → l'id è un uuid valido e `find($m->id)` ritrova il record (oggi l'errore è 42P01).
- [ ] **Step 2 — RED** su `mysql` (classe PG) e `pgsql`.
- [ ] **Step 3 — Implementazione** secondo l'opzione scelta.
- [ ] **Step 4 — Esempio** (metà PG): un modello con pk uuid.
- [ ] **Step 5 — Commit:** `fix: insert Postgres rows whose pk has a non-sequence default and read the key back (#153)`

**PR — Backward compatibility:** l'SQL degli insert cambia solo per le pk con default non-sequence, che oggi falliscono.

**Release note:** `Postgres models whose primary key has a non-sequence default (e.g. gen_random_uuid()) can be created without an explicit id (#153)`

### Task 34 — #154: `DatabaseException` espone SQLSTATE ed eccezione originale

**Issue:** #154 · **Branch:** `fix/154-database-exception-previous` · **Dipende da:** — · **Spec:** §5

**Gate G6** — Cosa cambiare in `DatabaseException`?
- **(A, raccomandata)** Messaggio identico a oggi; si aggiungono `getPrevious()` (la `PDOException`) e un metodo `sql_state(): ?string`.
- (B) Come (A), e in più il messaggio perde lo stack trace, mantenendo solo la prima riga dell'errore PDO.
- (C) Solo documentazione.

**Files:**
- Modify: `lib/Exceptions.php` (`DatabaseException`, ~35-52), `lib/Connection.php` (dove le query vengono avvolte nell'eccezione, ~362-375).
- Modify: `test/helpers/AdapterTest.php`.
- Example: non si applica, oppure una riga in `examples/simple/` che stampa `sql_state()`.

**Comportamento richiesto (A):**
- `getPrevious()` è un'istanza di `PDOException`.
- `sql_state()` restituisce `'42S22'` su MySQL e MariaDB, `'42703'` su PG e `'HY000'` su SQLite, per una colonna sconosciuta.
- Il messaggio è identico a oggi.

- [ ] **Step 1 — Test RED** in `AdapterTest`, su tutti gli adapter: `count(['conditions' => ['nmae' => 1]])` → `DatabaseException` con `previous` e `sql_state()` come sopra.
- [ ] **Step 2 — RED** su `mysql` (AdapterTest gira su tutti e quattro gli adapter).
- [ ] **Step 3 — Implementazione:** costruttore con `previous`, più il metodo `sql_state()`.
- [ ] **Step 4 — Commit:** `fix: keep the PDOException and its SQLSTATE on DatabaseException (#154)`

**PR — Backward compatibility (A):** solo aggiunte. Con (B) cambia il testo del messaggio, e il codice che oggi lo analizza va adattato.

**Release note:** `DatabaseException exposes the original PDOException (getPrevious()) and sql_state() (#154)`

### Task 35 — #156: PHPDoc delle relazioni autosufficienti

**Issue:** #156 · **Branch:** `fix/156-self-sufficient-relationship-phpdoc` · **Dipende da:** T28 · **Spec:** §5

**Gate G7** — Come rendere le PHPDoc utilizzabili senza lo stub?
- **(A, raccomandata)** Alias `@phpstan-type` locali su `Model`, e `@phpstan-var` più ampia che accetta anche le voci in forma di nome semplice (`Relationship|string`). Nessun cambio a runtime.
- (B) `require` di `lib/Relationship.php` da `ActiveRecord.php`: cambia l'ordine di caricamento a runtime.
- (C) Affidarsi solo all'extension e chiudere la issue.

**Files:**
- Modify: `lib/Model.php`: PHPDoc (80-81 e 268-291).
- Modify: il fixture `test/phpstan-consumer/` di T28, con una variante **senza** l'extension.
- Modify: `.github/workflows/ci.yml`: il job consumer di T28 aggiunge la variante senza extension.

**Comportamento richiesto (A):**
- Un consumer senza lo stub ottiene 0 errori sulle dichiarazioni di relazione.
- `$RUN $WT analyse`, cioè l'analisi di livello 8 di `lib/`, resta `[OK]`, senza nuove voci in baseline.

- [ ] **Step 1 — RED:** il job consumer senza extension sul codice di BASE dà `class.notFound` e `property.defaultValue` (spec §5, #156).
- [ ] **Step 2 — Implementazione:** le PHPDoc secondo l'opzione scelta.
- [ ] **Step 3 — GREEN:** consumer senza extension con 0 errori, `gate` PASS.
- [ ] **Step 4 — Commit:** `fix: make the relationship PHPDoc on Model resolvable without the stub (#156)`

**PR — Backward compatibility:** nessuna a runtime con (A). **Release note:** `PHPStan: relationship property types resolve in consumer projects even without the stub (#156)`

---

## Ondata 9 — Chiusura

### Task 36 — #16: eliminare la baseline PHPStan

**Issue:** #16 · **Branch:** `chore/16-remove-phpstan-baseline` · **Decisione:** D22 · **Dipende da:** T1–T35 (è l'ultimo task di codice) · **Spec:** §4.7

**Files:**
- Modify:
  - `lib/Config.php`: PHPDoc di `set_connections()`;
  - `lib/Model.php`: PHPDoc di `is_delegated()`, rimozione del `&` e `$this->__get($association_name);` in `__call`;
  - `lib/Relationship.php`: restringimento di `$this` con `instanceof`, nel ramo `through` di `query_and_attach_related_models_eagerly()` e nella join non-HasMany;
  - `lib/SQLBuilder.php`: PHPDoc del costruttore;
  - `lib/Validations.php`: rimozione del ramo morto `is_null` in `Errors::to_array()`.
- Modify: `phpstan.neon` (togli `includes`). Delete: `phpstan-baseline.neon`.
- Modify: `CLAUDE.md`: la frase sulla baseline "frozen" diventa "There is no PHPStan baseline: new code must pass level 8 with no suppressions".
- Example: non si applica.

**Comportamento richiesto:** nessun cambio a runtime.

| Voce | Cosa fare |
|---|---|
| 1. `Config::set_connections()` | PHPDoc `@param array<string, string>\|null`; la guardia resta |
| 2. `Model::is_delegated()` | PHPDoc `array<int\|string, mixed>\|bool` e via il `&`. Non è codice morto: riceve `true` da `'processed' => true` |
| 3. `__call` | `$this->__get($association_name);`, non `read_attribute()`, che salterebbe un getter `get_<nome>` |
| 4. `Relationship.php` | nel ramo `through`, `if (!$this instanceof HasMany) { throw new RelationshipException(…); }` (irraggiungibile da #24); nella join, `elseif ($this instanceof BelongsTo) {…} else { throw new RelationshipException(…); }` |
| 5. `SQLBuilder::__construct` | PHPDoc `@param Connection\|null`; la guardia resta |
| 6. `Errors::to_array()` | rimuovi il ramo `is_null` morto |

- [ ] **Step 1 — Errori di partenza:** rimuovi l'`includes` da `phpstan.neon` ed esegui `$RUN $WT analyse`. Ottieni gli errori con le posizioni correnti (sul codice di `583fd8e` erano 8; vedi spec §4.7). I numeri di riga saranno cambiati con i task precedenti.
- [ ] **Step 2 — Correggi** le 6 voci come da tabella; tieni come riferimento il diff di prova della verifica (`trialC-final.diff`, descritto nella spec).
- [ ] **Step 3 — Verifica:**
  - `$RUN $WT analyse` → `[OK] No errors`, anche con `PHPAR_IMAGE=php-activerecord-tests:8.5`;
  - `gate` PASS; `pgsql` verde;
  - coprono i percorsi toccati: `ConfigTest::test_set_connections_must_be_array`, `SQLBuilderTest::test_no_connection`, i test sui delegate e sui builder `build_`/`create_`, `HasManyThroughTest`, `ErrorsTest`.
- [ ] **Step 4 — Commit:** `chore: remove the PHPStan baseline by fixing its six entries (#16)`

**PR — Backward compatibility:** nessuna. I rami nuovi sono irraggiungibili con le classi della libreria; li raggiungerebbe solo una sottoclasse di terze parti di `AbstractRelationship`, che riceverebbe `RelationshipException` invece di warning. **Release note:** `Internal: the PHPStan baseline is gone; lib/ passes level 8 with no suppressions (#16)`

### Task 37 — Guida di upgrade da `zamzar/php-activerecord` 1.7

**Issue:** — (D25; Refs #91) · **Branch:** `docs/upgrading-guide` · **Dipende da:** T1–T36 · **Spec:** §6

**Files:**
- Create: `UPGRADING.md` nella root.
- Modify: `README.md`, con un link a `UPGRADING.md` nella sezione d'installazione.
- Example: non si applica.

**Contenuto richiesto** (in inglese, come il README):
1. **Requisiti:** PHP `^8.3`; MySQL 8+, MariaDB 10.11+, PostgreSQL 15+; l'adapter OCI è stato rimosso.
2. **Tutte le voci BREAKING** delle release 2.0.0, 2.1.0 e 2.2.0 (`gh release view <tag>`) e di questo plan: le "Release note" marcate **BREAKING** delle PR di T1–T35. Per ciascuna: cosa cambia e cosa deve fare chi aggiorna.
3. **Due blocchi già verificati nel monolite** (spec §6), con il rimedio lato consumer:
   - le opzioni sconosciute nelle relazioni sono rifiutate da #24: una chiave custom come `'constraints'` va spostata fuori dalle dichiarazioni, ad esempio in uno static separato letto dal modello;
   - le proprietà statiche di relazione ridichiarate con un tipo nativo (`public static array $belongs_to`) causano un errore fatale, perché il fork le dichiara senza tipo: va tolto il tipo.
4. **Checklist di verifica per chi aggiorna:**
   - eseguire la suite del consumer, compresa quella SQLite (per #67);
   - cercare i warning `Undefined array key "conditions"` nei log (#145);
   - controllare i punti che emettono il JSON di modelli appena creati (#55).

- [ ] **Step 1 — Raccogli** le note: `gh release view 2.0.0`, `2.1.0`, `2.2.0` (draft) e le PR mergiate nel ponte.
- [ ] **Step 2 — Scrivi** `UPGRADING.md` e aggiungi il link nel README.
- [ ] **Step 3 — Verifica:** `$RUN $WT cs` e `gate` PASS; i link interni funzionano.
- [ ] **Step 4 — Commit:** `docs: add an upgrade guide from zamzar/php-activerecord 1.7`

---

## Chiusura del plan

- [ ] Dopo il merge in `master` dell'ultima ondata:
  - tutte le issue di questo plan sono chiuse (#142 come "not planned");
  - `.superpowers/bin/sync-tracker` porta il tracker #91 tutto spuntato;
  - il controller chiude #91 con un commento di riepilogo: PR per ondata e issue nuove aperte strada facendo.
- [ ] Il maintainer pubblica la release con le note raccolte dalle PR d'ondata.
- [ ] Il ponte `integration/2026-10-issues` viene cancellato solo quando il maintainer lo decide.
