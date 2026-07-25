# DB Manager — Plan van aanpak (voor Claude Code)

Een complete, snelle SQLyog-achtige database manager als cross-platform desktop-app (Linux, Windows, macOS). Doel: dagelijks bruikbaar als volwaardige vervanger van SQLyog voor MySQL/MariaDB-werk.

## Stack (vaststaand — niet van afwijken)

- **Laravel 13** (PHP 8.3+ vereist)
- **NativePHP desktop v2** (Electron-driver) — desktop-schil
- **Livewire 4** + **Alpine.js** + **TailwindCSS 4** — UI
- **CodeMirror 6** — SQL-editor (via npm, gebundeld met Vite)
- **SQLite** — interne app-database (connecties, query-historie, instellingen)
- **Doel-databases: uitsluitend MySQL/MariaDB** (via pdo_mysql)
- Taal van de UI en code: **Engels** (project wordt mogelijk open source)

## Kernprincipes

1. Snel en vloeiend: geen onnodige full-page reloads; Livewire + Alpine voor interactie, `wire:stream`/polling voor progress; grids met veel rijen virtueel renderen of pagineren.
2. Veiligheid: opgeslagen wachtwoorden altijd encrypted (Laravel `Crypt`); export van credentials standaard versleuteld.
3. Nooit destructieve acties zonder bevestiging (DROP, DELETE van rijen, TRUNCATE, overschrijven bij copy/import).
4. Alle MySQL-metadata via `information_schema` en `SHOW`-statements; ondersteun MySQL 5.7+/MariaDB 10.4+.
5. Elke fase eindigt met werkende, geteste functionaliteit (Pest-tests voor services; UI handmatig).

## UI-structuur (gemodelleerd naar SQLyog — dit is leidend)

Per connectie-tab, drie zones:

**Links — Object Explorer (sidebar):**
- Zoek-/filterveld bovenin dat de tree live filtert (optioneel "Search as Regex"-checkbox).
- Tree: databases → uitvouwbaar → Tables / Views (lazy loaded), per tabel uitvouwbaar naar kolommen en indexes.
- Onderin statusregel met actieve database.

**Rechtsboven — Query-editor:**
- CodeMirror 6, meerdere query-tabs naast elkaar ("Query 1", "Query 2", + knop).
- Hint-regel met sneltoetsen (zoals SQLyog's autocomplete-hints).

**Rechtsonder — Resultaten-paneel met drie vaste tabs:**
1. **Messages** — uitvoerlog: per statement status, rows affected, execution time, fouten/warnings.
2. **Table Data** — data-grid van de geselecteerde tabel of het query-resultaat (zie Data Grid hieronder).
3. **Info** — van de geselecteerde tabel: Columns (veld, type, NULL, key-indicator), Indexes (naam, kolommen, type), en DDL (`SHOW CREATE TABLE`) in een code-block met copy-knop. Refresh-knop bovenaan.

Klik op een tabel in de tree → Table Data + Info vullen zich voor die tabel. Query uitvoeren → resultaat in Table Data, log in Messages.

## Data Grid (Table Data-tab) — functionele eisen

**Toolbar (zoals SQLyog):**
- Filter-knop (opent Custom Filter-dialog, zie hieronder), Refresh-knop.
- "Limit rows"-checkbox + "First row" (offset) + "# of rows" (default 1000) met vorige/volgende-pijltjes om door pages te bladeren.

**Custom Filter-dialog:**
- Meerdere filterregels: Field Name (dropdown met kolommen) / Condition (=, <>, >, <, >=, <=, LIKE, NOT LIKE, IS NULL, IS NOT NULL, IN, BETWEEN) / Value.
- Regels gecombineerd met AND (v1; OR-groepen later).
- "Show SQL Preview"-link die de gegenereerde WHERE-clause toont.
- Actieve filters zichtbaar als chips boven het grid, per chip verwijderbaar.
- Daarnaast: snelfilter via rechtermuisknop op een cel (zie contextmenu's).

**Grid zelf:**
- Sorteren per kolomheader (klik = asc/desc/unsort), kolombreedte slepen.
- NULL visueel onderscheiden (grijs cursief `(NULL)`), grote text/blob-waarden ingeklapt met grootte-indicator (bv. "88B") en klik-om-te-bekijken (viewer-dialog met text/hex-weergave).
- Checkbox-kolom links voor rij-selectie (voor delete/export/copy).
- Celwaarde kopiëren; rij(en) kopiëren als CSV/tab-separated/INSERT-statement.

**Inline data-editing (belangrijk — SQLyog-pariteit):**
- Dubbelklik op cel = bewerken (input passend bij kolomtype; datetime-picker voor timestamps, dropdown voor enum).
- Gewijzigde cellen gemarkeerd; "Save Changes" / "Cancel Changes" in toolbar én contextmenu.
- Nieuwe rij invoegen (lege editregel onderaan), rij dupliceren, geselecteerde rijen verwijderen (met bevestiging).
- Updates via primary key; tabel zonder PK = read-only grid met melding.
- Cel-acties: Set To NULL, Set To Empty String, Set To Default.

**Foreign key drill-down (belangrijk):**
- Detecteer FK-kolommen via `information_schema.KEY_COLUMN_USAGE` (per tabel cachen).
- In cellen van FK-kolommen: een "…"-knopje rechts in de cel (zichtbaar bij hover).
- Klik opent een dialog met het volledige gerelateerde record (alle kolommen verticaal: veldnaam + waarde), incl. tabelnaam en de gebruikte relatie.
- In die dialog: knop "Open in Table Data" (springt naar de doeltabel met filter op die key) en — als het gerelateerde record zelf FK-kolommen heeft — dezelfde "…"-drill-down, zodat je relaties kunt doorlopen.
- Werkt ook zonder gedefinieerde FK-constraint via conventie-detectie: kolom `xxx_id` → tabel `xxxs`/`xxx` met kolom `id` (Laravel-conventie), als die tabel bestaat. Markeer conventie-matches subtiel anders dan echte FK's.

## Contextmenu's (rechtermuisknop)

**Op een tabel in de tree** — bouw wat technisch haalbaar is, in deze prioriteitsvolgorde:
1. Open Table (Table Data-tab) / Open Table in New Query Tab
2. Copy Table(s) To Different Host/Database… (opent CopyWizard, fase 4)
3. Backup/Export → Export als SQL (ExportWizard) / Export data als CSV of JSON
4. Import → SQL-bestand uitvoeren op deze database
5. Paste SQL Statement → submenu: SELECT / INSERT / UPDATE / DELETE / CREATE-template met alle kolommen ingevuld, geplakt in de query-editor
6. Create Table… (eenvoudige dialog: naam, kolommen met type/null/default/PK/AI) — v1 mag ook een CREATE TABLE-template in de editor plakken
7. Alter Table → v1: `SHOW CREATE TABLE` in editor plakken om aan te passen; latere versie: visuele alter-dialog
8. Manage Indexes → dialog: bestaande indexes tonen, toevoegen/verwijderen
9. Relationships/Foreign Keys → dialog: FK's tonen, toevoegen/verwijderen
10. More Table Operations → Truncate (met bevestiging), Drop (met bevestiging + naam overtypen), Rename, Duplicate table (structuur / structuur+data)
11. Refresh
(Triggers: alleen tonen/bekijken in v1, beheer later.)

**Op een database in de tree:** Create Table…, Export database, Import SQL, Refresh, Drop database (dubbele bevestiging).

**Op een cel/rij in het grid** (SQLyog-pariteit):
- Insert New Row, Duplicate Current Row, Delete Selected Row(s)
- Save Changes / Cancel Changes (alleen actief bij pending edits)
- Set To NULL / Set To Empty String / Set To Default
- Filter → submenu: "= deze waarde", "<> deze waarde", "LIKE %waarde%", verwijder filters
- Unsort
- Copy → cel / rij als CSV / rij als INSERT
- Export All Rows Of Table Data/Result As… → CSV / JSON / SQL INSERTs (native save-dialog)
- (FK-kolom: ook "Show related record" = zelfde als "…"-knop)

Contextmenu's bouwen als eigen Alpine-component (positioneren op muispositie, sluiten bij klik erbuiten/Escape) — geen browser-defaultmenu.

## Datamodel (interne SQLite)

**connections**
- id, name (uniek), color (voor tab-accent, nullable)
- host, port (default 3306), username, password (encrypted, nullable)
- use_ssh (bool), ssh_host, ssh_port (default 22), ssh_username, ssh_auth_type (password|key), ssh_password (encrypted, nullable), ssh_key_path (nullable)
- default_database (nullable), timestamps

**query_history**
- id, connection_id (FK, nullable on delete set null), database, query (text), duration_ms, rows_affected, executed_at

**settings** — key/value (theme, editor-instellingen, laatst geopende tabs, kolombreedtes per tabel optioneel)

## Architectuur

- `app/Services/ConnectionManager.php` — registreert runtime een Laravel DB-connectie (`config(['database.connections.conn_{id}' => ...])`), test connecties, beheert open/dicht.
- `app/Services/SshTunnel.php` — spawnt `ssh -N -L {localPort}:{dbHost}:{dbPort} {user}@{sshHost}` via Symfony Process; vrije lokale poort automatisch kiezen; tunnel-lifecycle gekoppeld aan tab. Windows: ingebouwde OpenSSH (`ssh.exe`). Key-auth via `-i {keyPath}`; password-auth via `sshpass` indien beschikbaar, anders duidelijke foutmelding.
- `app/Services/SchemaExplorer.php` — databases, tabellen (+ rijschatting, engine, size), views, kolommen, indexes, FK's (KEY_COLUMN_USAGE) via information_schema; caching per tab met invalidatie bij Refresh.
- `app/Services/QueryRunner.php` — voert (meerdere) statements uit, meet duration, vangt fouten netjes af, default LIMIT-injectie op ongelimiteerde SELECTs (melding + uitzetbaar), schrijft naar query_history.
- `app/Services/DataEditor.php` — genereert veilige UPDATE/INSERT/DELETE op basis van PK + gewijzigde velden (prepared statements), valideert dat een PK aanwezig is.
- `app/Services/FilterBuilder.php` — vertaalt filterregels naar veilige WHERE-clause met bindings.
- `app/Services/RelationResolver.php` — FK-detectie (echte constraints + Laravel-conventie `xxx_id`) en ophalen van gerelateerde records.
- `app/Services/TableCopier.php` — kopieert tabellen/views tussen connecties (fase 4).
- `app/Services/SqlDumper.php` — eigen PHP-dumper, geen mysqldump-dependency (fase 5); ook CSV/JSON-export van resultsets.
- `app/Services/SqlImporter.php` — voert een .sql-bestand statement-voor-statement uit met progress en foutrapportage.
- `app/Services/ConnectionPorter.php` — export/import van connecties (fase 1b).
- Livewire-componenten onder `app/Livewire/`: `ConnectionSidebar`, `ConnectionForm`, `Workspace` (connectie-tabs), `ObjectExplorer`, `QueryEditor`, `ResultsPanel` (Messages/Table Data/Info), `DataGrid`, `FilterDialog`, `RecordDialog` (FK drill-down), `CopyWizard`, `ExportWizard`, `ImportDialog`, `IndexManager`, `ForeignKeyManager`, `ContextMenu` (Alpine).

## Fasen — in deze volgorde bouwen, per fase committen

### Fase 0 — Fundament
- Laravel 13-project + NativePHP desktop v2 (`nativephp/electron`), `php artisan native:install`.
- Tailwind 4 + Livewire 4 + Alpine via Vite; CodeMirror 6 via npm.
- Venster-config: min. 1200x800, app-naam "DB Manager", donker thema als default.
- SQLite-migrations voor het datamodel.
- Basislayout volgens de UI-structuur hierboven: connectielijst-sidebar, connectie-tabs, en binnen een tab het drieluik (Object Explorer / Query-editor / Resultaten-paneel) als lege skeletten met versleepbare splitters.
- Definition of done: `php artisan native:serve` toont het volledige lege raamwerk.

### Fase 1 — Connecties & browsen
- Connectie-CRUD (modal) incl. SSH-velden (conditioneel) en "Test connection" met duidelijke foutmeldingen.
- Dubbelklik op connectie opent tab; meerdere tabs; tab sluiten sluit eventuele tunnel.
- ObjectExplorer met zoekfilter, lazy loading, rij-aantallen.
- Table Data-grid read-only: paginering via Limit rows/First row/# of rows, sorteren, refresh, NULL/blob-weergave.
- Info-tab: Columns, Indexes, DDL.
- Messages-tab logt alle acties.

### Fase 1b — Connectie-export/import
- Export: selectie van connecties → (a) **encrypted `.dbmconn`** (JSON payload, `sodium_crypto_secretbox`, key via `sodium_crypto_pwhash`/Argon2id, random salt + nonce in header, passphrase van gebruiker) of (b) plain JSON met expliciete waarschuwing.
- Import: file-picker of drag-and-drop → passphrase bij `.dbmconn` → preview → per connectie import / skip / overwrite bij naamconflict.
- Bestandsformaat versioneren (`"format_version": 1`).

### Fase 2 — Query-editor
- CodeMirror 6 met SQL-dialect, autocompletion op tabel-/kolomnamen van de actieve database, Ctrl+Enter = uitvoeren, Ctrl+Shift+Enter = selectie uitvoeren.
- Meerdere query-tabs; meerdere statements (correct splitsen op `;` buiten strings/comments) sequentieel; resultaten naar Table Data, log naar Messages.
- EXPLAIN-knop op de actieve SELECT.
- Query-historie-paneel (per connectie, doorzoekbaar, klik = terug in editor).

### Fase 3 — Filters, editing & relaties (de "compleet"-fase)
- Custom Filter-dialog + WHERE-chips + snelfilters via celcontextmenu (FilterBuilder).
- Inline editing: DataEditor, dirty-state, Save/Cancel, insert/duplicate/delete rijen, Set To NULL/Empty/Default, read-only fallback zonder PK.
- FK drill-down: RelationResolver, "…"-celknop, RecordDialog met doorklikbare relaties en "Open in Table Data".
- Alle contextmenu's uit de sectie hierboven (tree: minimaal items 1, 5, 10-Truncate/Drop/Rename, 11; grid: volledig). Wat in deze fase niet af komt van het tree-menu, schuift naar fase 5b.

### Fase 4 — SSH-tunneling afronden + kopiëren tussen connecties
- SshTunnel productieklaar: retries, timeout, poortconflict-afhandeling, statusindicator per tab; tunnels opruimen bij app-quit (NativePHP shutdown-hook).
- Checkboxes op tabellen/views in de tree; CopyWizard: doel = andere open connectie + database; structure only / structure + data; bij bestaand object skip / drop & recreate.
- `SHOW CREATE TABLE|VIEW` → aanmaken op doel → data in chunks van 1000 rijen (batched INSERTs), `FOREIGN_KEY_CHECKS=0` op doelsessie; eerst tabellen, dan views; live progress per tabel; fouten per object rapporteren zonder de run te stoppen; eindsamenvatting.

### Fase 5 — Export & import
- ExportWizard: hele database of selectie → structure / data / beide → optioneel DROP IF EXISTS + CREATE DATABASE → .sql via native save-dialog.
- SqlDumper in pure PHP: batched multi-row INSERTs (max ~1 MB per statement), escaping via PDO quote, binaire kolommen als hex (`0x...`), header-comment, `FOREIGN_KEY_CHECKS=0`-wrapper, streaming naar disk.
- Resultset-export naar CSV/JSON vanuit het grid.
- SqlImporter: .sql-bestand uitvoeren met progress en foutlog (Messages-tab).

### Fase 5b — Schema-beheer
- Create Table-dialog, Manage Indexes, Relationships/Foreign Keys-dialog, Alter via DDL-in-editor, Paste SQL Statement-templates — alles wat uit fase 3 doorgeschoven is.

### Fase 6 — Polish & release
- Sneltoetsen-overzicht (F5/Ctrl+R refresh, Ctrl+Enter run, Ctrl+F filter, F11 open table), licht/donker thema-toggle, tab-herstel bij opstarten.
- README (Engels) met screenshots, MIT-licentie.
- Buildscripts/CI-notities voor `php artisan native:build` per platform (linux AppImage/deb, windows exe, mac dmg).

## Conventies

- Pest voor tests; services testbaar zonder echte MySQL waar mogelijk (FilterBuilder, SqlDumper-formatting, ConnectionPorter-encryptie zijn puur unit-testbaar).
- PHP 8.3-features en Laravel 13 attributes waar zinvol.
- Kleine, gerichte commits per feature; conventional commits (feat/fix/chore).
- Geen extra composer/npm-packages toevoegen zonder duidelijke noodzaak.

## Instructies voor Claude Code

- Lees dit hele document voordat je begint.
- Werk de fases in volgorde af: 0 → 1 → 1b → 2 → 3 → 4 → 5 → 5b → 6.
- **Stop na fase 1** zodat de gebruiker de UI en het gevoel van de app kan beoordelen; ga daarna op diens akkoord door t/m fase 6 zonder verdere stops.
- Commit na elke fase (en tussendoor per afgeronde feature).
- Bij twijfel over UI-details: volg SQLyog als referentie — dat is het mentale model van de gebruiker.
