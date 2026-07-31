# PER-CS 3.0 Coding Style Implementation Plan (PR #2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adottare il coding style **PER-CS 3.0** (regole non-risky) su tutto il codice PHP del repo, applicato con PHP-CS-Fixer ed enforced da uno step CI bloccante.

**Architecture:** Si sostituisce `squizlabs/php_codesniffer` (dev dep inutilizzata) con `friendsofphp/php-cs-fixer`. Un `.php-cs-fixer.dist.php` configura il ruleset `@PER-CS3.0` su `lib/`, `test/`, `examples/` e i file PHP di root. Il reformat di massa (tab → 4 spazi, ecc.) va in **un commit meccanico isolato** per la reviewabilità. Uno step CI nel job PHP 8.3 fa fallire la build se lo stile non è conforme.

**Tech Stack:** PHP 8.3+, PHP-CS-Fixer 3.x (ruleset `@PER-CS3.0`), GitHub Actions.

## Global Constraints

- PHP floor `^8.3`.
- **Solo regole non-risky** (`@PER-CS3.0`, niente `:risky`) — nessun cambio semantico.
- PER-CS 3.0 impone **4 spazi** di indentazione (il legacy usa tab): il diff toccherà quasi ogni file — è atteso e voluto.
- Lo **snake_case** dell'API pubblica e dei metodi di test (`set_up`, `test_*`) NON è toccato: PER-CS è formattazione, non naming.
- Merge **dopo** la PR #1 (PHPStan). La baseline PHPStan usa `message+identifier+count+path` (no line numbers) → il reformat non la invalida.
- Verifica in Docker: `docker compose exec tests composer run test`, `... composer run cs`, `... composer run analyse`.
- Branch dedicato per questa PR.

---

### Task 1: Sostituire la toolchain e configurare PHP-CS-Fixer

**Files:**
- Modify: `composer.json` (`require-dev`, `scripts`)
- Create: `.php-cs-fixer.dist.php`
- Modify: `.gitignore`
- Modify: `.github/workflows/ci.yml`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Creare il branch (dopo che PR #1 è in master)**

```bash
git checkout master && git pull
git checkout -b per-cs-3-style
```

- [ ] **Step 2: Aggiornare `composer.json`** — rimuovere php_codesniffer, aggiungere php-cs-fixer, aggiungere gli script

In `require-dev` togliere la riga `"squizlabs/php_codesniffer": "^3.10",` e aggiungere:
```json
"friendsofphp/php-cs-fixer": "^3.68"
```
In `scripts` aggiungere:
```json
"cs": "php-cs-fixer fix --dry-run --diff",
"cs-fix": "php-cs-fixer fix"
```
(`^3.68` è la prima release con il ruleset `@PER-CS3.0`; verificare la versione risolta con `composer why friendsofphp/php-cs-fixer` dopo l'update e, se serve, alzare il vincolo.)

- [ ] **Step 3: Creare `.php-cs-fixer.dist.php`**

```php
<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/lib', __DIR__ . '/test', __DIR__ . '/examples'])
    ->append([__DIR__ . '/ActiveRecord.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS3.0' => true,
    ])
    ->setFinder($finder);
```

- [ ] **Step 4: `.gitignore`** — aggiungere la cache del fixer

Aggiungere la riga:
```
.php-cs-fixer.cache
```

- [ ] **Step 5: Installare e verificare che il tool risolva il ruleset**

```sh
docker compose exec tests composer update --no-interaction
docker compose exec tests vendor/bin/php-cs-fixer describe @PER-CS3.0 >/dev/null && echo "ruleset OK"
```
Expected: `ruleset OK`. Se `describe` fallisce, la versione di php-cs-fixer è troppo vecchia → alzare il vincolo in `composer.json` e rifare l'update.

- [ ] **Step 6: Aggiungere lo step CI** in `.github/workflows/ci.yml`, subito dopo lo step "Static analysis" (stesso gate `matrix.php-version == '8.3'`):

```yaml
      - name: Coding style
        if: matrix.php-version == '8.3'
        run: composer run cs
```

- [ ] **Step 7: Aggiornare `CLAUDE.md`**
  - Sezione "Commands": aggiungere
    ```sh
    docker compose exec tests composer run cs        # PHP-CS-Fixer dry-run (CI gate)
    docker compose exec tests composer run cs-fix    # applica PER-CS 3.0
    ```
  - Sezione "Conventions that matter": sostituire la descrizione dello stile legacy "tabs for indentation" con: lo stile del repo è **PER-CS 3.0 (4 spazi)**, enforced da `friendsofphp/php-cs-fixer` (`.php-cs-fixer.dist.php`, ruleset `@PER-CS3.0` non-risky) e verificato in CI (job 8.3). La regola snake_case dell'API e la regola modern-PHP restano invariate.

- [ ] **Step 8: Commit (tooling + config, SENZA il reformat di massa)**

```bash
git add composer.json composer.lock .php-cs-fixer.dist.php .gitignore .github/workflows/ci.yml CLAUDE.md
git commit -m "build: adopt PHP-CS-Fixer with PER-CS 3.0, add CI style gate"
```
Nota: a questo punto `composer run cs` fallirebbe (legacy non conforme). È atteso: il reformat è il task successivo, e CI valuta lo **stato finale** del branch.

---

### Task 2: Reformat di massa (commit meccanico isolato)

**Files:** tutti i `.php` sotto `lib/`, `test/`, `examples/` + `ActiveRecord.php`.

- [ ] **Step 1: Applicare il fixer**

```sh
docker compose exec tests composer run cs-fix
```

- [ ] **Step 2: Verificare conformità (dry-run deve essere pulito)**

```sh
docker compose exec tests composer run cs
```
Expected: nessun diff, exit 0.

- [ ] **Step 3: Suite completa verde** (le regole non-risky non cambiano semantica; questa è la rete)

```sh
docker compose exec tests composer run test
```
Expected: PASS (nessun test skipped/risky/deprecated).

- [ ] **Step 4: PHPStan ancora verde** (il reformat non deve invalidare la baseline né introdurre errori)

```sh
docker compose exec tests composer run analyse
```
Expected: `[OK] No errors`. Se emergono errori nuovi, sono causati dal reformat → investigare prima di procedere (non aggiungere voci di baseline per nasconderli).

- [ ] **Step 5: Commit meccanico isolato**

```bash
git add -A
git commit -m "style: apply PER-CS 3.0 across lib/, test/, examples/ (mechanical, no logic changes)"
```

---

### Task 3: Verifica finale + PR

- [ ] **Step 1: Confermare che tutti e tre i gate passano**

```sh
docker compose exec tests composer run cs
docker compose exec tests composer run analyse
docker compose exec tests composer run test
```
Expected: tutti PASS.

- [ ] **Step 2: Push + PR**

```bash
git push -u origin per-cs-3-style
gh pr create --title "Adopt PER-CS 3.0 coding style" \
  --body "Sostituisce php_codesniffer con PHP-CS-Fixer (@PER-CS3.0 non-risky), reformat repo-wide in un commit meccanico isolato, CI style gate sul job 8.3. Review consigliata con \`git diff -w\`."
```

---

## Self-Review

- **Spec coverage:** tool swap + config → Task 1; reformat isolato → Task 2; CI bloccante → Task 1 Step 6; scope lib/test/examples/root → finder in `.php-cs-fixer.dist.php`; doc CLAUDE.md → Task 1 Step 7; verifica → Task 3. ✓
- **Placeholder scan:** nessuno; config e step CI sono contenuti letterali. ✓
- **Type consistency:** N/A (nessuna nuova API); i nomi degli script (`cs`, `cs-fix`) sono usati coerentemente in composer.json, CLAUDE.md e CI. ✓
- **Nota rischio:** `@PER-CS3.0` richiede php-cs-fixer ≥ 3.68; Task 1 Step 5 lo verifica con `describe` e prescrive come reagire se assente.
