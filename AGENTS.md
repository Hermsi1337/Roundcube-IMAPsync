# AGENTS.md — Roundcube IMAPsync Plugin

This file is the canonical context document for any AI coding agent (Claude, Codex, Cursor, etc.)
working in this repository. `CLAUDE.md` is a symlink to this file.

---

## What this repository is

A Roundcube webmail plugin called **`imapsync`** that lets the logged-in user pull mail from
another IMAP account into their currently active Roundcube account. Use case: account migration
("get my old mails from server X into the account I'm using right now").

This is **not** a wrapper around the Perl `imapsync` CLI tool. The synchronization is implemented
natively in PHP using Roundcube's own `rcube_imap_generic` class. The Perl tool is referenced only
as a behavioral inspiration for edge-case handling (UID dedup, header-only message identity,
folder mapping).

### Sync direction

**One-way only: remote → local.** The user is logged into Roundcube (= destination). The plugin
form takes credentials for a remote IMAP server (= source). Messages flow from remote to local.
There is no upload-from-local path; do not add one without an explicit product decision.

### Execution model

**Synchronous, AJAX-driven.** User clicks "Start sync", the browser holds a single AJAX call
open while the plugin walks folders and appends messages on the destination. The response of
that call carries the final result; the browser shows a "loading" indicator until then. No
mid-run progress is exposed to the client — see "Open work / known limits" below.

The synchronous model is an **MVP choice**, not a long-term architecture. Large mailboxes will hit
PHP `max_execution_time` and browser timeouts. A background-worker mode (DB-persisted jobs, cron
runner, status polling) is planned but explicitly out of scope for this iteration. When you touch
the sync engine, keep the entry point cleanly separable so a worker can call it later — do not
hard-couple the engine to the HTTP response. The engine's `run()` method takes a `callable
$progress` argument that is currently invoked with a no-op; the worker rewrite will wire that
callback up to a real progress store (file or shared cache).

### Session locking

PHP sessions are write-locked per request. `action_start()` calls `session_write_close()` at the
very beginning of the request so the long-running sync does not hold the lock and block every
other AJAX call the browser fires during the sync (mailbox refresh, idle ping, this plugin's
own status poll). Consequence: anything written to `$_SESSION` after the lock is released is
**not persisted**. Any state that needs to outlive the request — e.g. mid-run progress for a
future worker mode — must use an out-of-session store (file, DB, Roundcube cache API).

---

## Repository layout

```
.
├── AGENTS.md                                 # this file
├── CLAUDE.md                                 # symlink → AGENTS.md
├── README.md                                 # user-facing documentation
├── composer.json                             # plugin manifest (Roundcube plugin-installer type)
├── imapsync.php                              # plugin entry class (extends rcube_plugin)
├── imapsync.js                               # client-side: form handling, result rendering
├── config.inc.php.dist                       # default plugin config, copied by user to config.inc.php
├── lib/
│   ├── RoundcubeImapSyncClient.php           # IMAP client interface + rcube_imap_generic adapter
│   ├── RoundcubeImapSyncEngine.php           # core sync logic (folder walk, UID dedup, append)
│   ├── RoundcubeImapSyncException.php        # plugin-specific RuntimeException subclass
│   ├── RoundcubeImapSyncJob.php              # parameter object: source creds + options
│   └── RoundcubeImapSyncResult.php           # tallies (folders synced, messages copied, skipped, errors)
├── localization/
│   ├── en_US.inc                             # source of truth
│   └── de_DE.inc                             # must mirror en_US
├── skins/
│   └── elastic/
│       ├── imapsync.css
│       └── templates/
│           └── imapsync.html
├── tests/
│   ├── bootstrap.php
│   ├── ImapSyncEngineTest.php                # unit, uses FakeImapClient
│   ├── ImapSyncJobTest.php                   # unit
│   ├── Fakes/
│   │   └── FakeImapClient.php                # in-memory RoundcubeImapSyncClient for unit tests
│   └── Integration/
│       ├── bootstrap.php
│       ├── DovecotContainer.php              # testcontainers-php wrapper for Dovecot
│       └── SyncEngineIntegrationTest.php     # real-IMAP sync against two Dovecot containers
├── phpunit.xml.dist                          # unit suite (excludes tests/Integration)
├── phpunit.integration.xml.dist              # integration suite (Docker required)
├── .gitattributes                            # export-ignore tests/, dist/, .github/, etc.
├── .github/
│   └── workflows/
│       └── ci.yml                            # unit + integration tests, lint, docs-freshness
└── dist/                                     # gitignored — local Roundcube source for reference (see below)
```

### `dist/` directory

**Gitignored.** Holds an unpacked Roundcube release used as read-only reference while developing
the plugin (looking up plugin API signatures, copying patterns from existing plugins, etc.). Not
shipped, not required at runtime.

To re-create it:

```bash
mkdir -p dist && cd dist
curl -L -o roundcubemail-1.7.0-complete.tar.gz \
  https://github.com/roundcube/roundcubemail/releases/download/1.7.0/roundcubemail-1.7.0-complete.tar.gz
tar -xzf roundcubemail-1.7.0-complete.tar.gz
rm roundcubemail-1.7.0-complete.tar.gz
```

Roundcube download index: <https://roundcube.net/download/>.

Useful files inside `dist/roundcubemail-1.7.0/`:

- `program/lib/Roundcube/rcube_imap_generic.php` — the IMAP client we drive
- `program/lib/Roundcube/rcube_plugin.php` — base class our plugin extends
- `program/lib/Roundcube/rcube_plugin_api.php` — hook/action registry, names of available hooks
- `plugins/archive/` — small, modern plugin showing tasks, hooks, locales, settings hook
- `plugins/managesieve/` — larger plugin showing a settings sub-section with its own UI + AJAX

---

## External references

Pin these in your context when working on the plugin. They evolve out-of-tree, so re-check
periodically.

- **Roundcube Plugin API wiki** — <https://github.com/roundcube/roundcubemail/wiki/Plugin-API>
  Authoritative list of plugin hooks, actions, the `rcube_plugin` base class surface, and the
  client-side `rcmail.addEventListener` / `rcmail.command` patterns.
- **Roundcube plugin repository (third-party)** — <https://plugins.roundcube.net/>
  Real-world examples for less common patterns.
- **`imapsync` (Perl CLI) source** — <https://github.com/imapsync/imapsync>
  Behavioral reference for edge cases: message identity (`Message-ID` + size + internal date +
  subject hash), per-folder UID maps, Gmail label quirks, `\Recent` flag handling. **Do not
  shell out to it.** Only read it for ideas.
- **Old, unmaintained Roundcube IMAPsync plugin** — <https://github.com/server-gurus/RCimapSync>
  Predecessor of this plugin. Not used as a code source — its code is old and largely
  superseded by current Roundcube APIs — but worth a glance for UX ideas.
- **Roundcube `rcube_imap_generic` IMAP client** — read directly from `dist/`. Public methods
  we use most:
  - `connect($host, $user, $password, $options)`
  - `listMailboxes($ref, $pattern)`
  - `fetchHeaders($mailbox, $message_set, $is_uid, $bodystr, $add_headers)`
  - `append($mailbox, &$message, $flags, $date, $binary)`
  - `createFolder($folder)`
  - `getHierarchyDelimiter()`

---

## Conventions

These are the rules an agent should follow when generating or editing code in this repo. They
exist on top of the user's global Claude/Codex instructions — when in conflict, the user's
global rules win.

### PHP style

- **PHP 8.1+** is the minimum target. Use typed properties, constructor property promotion,
  `readonly` where appropriate, `match` expressions over long `switch` ladders.
- **PSR-12** formatting. Four-space indent, opening brace on the same line for control flow
  and on a new line for classes/functions, one blank line between methods.
- Namespaces: this plugin does **not** use PHP namespaces, because Roundcube's plugin loader
  expects the plugin's main class to be globally named after the plugin directory
  (here: `class imapsync extends rcube_plugin`). Helper classes under `lib/` follow the same
  convention: globally named with a `RoundcubeImapSync` prefix
  (`RoundcubeImapSyncEngine`, `RoundcubeImapSyncJob`, `RoundcubeImapSyncResult`), autoloaded
  via the `composer.json` `classmap`.
- **No comments unless intent is non-obvious.** Do not restate what code does. Comment a
  workaround, a hidden invariant, or a non-obvious choice. PHPDoc only on public class API
  where types are not expressible inline (e.g. arrays with known shapes).
- Error handling: never `die()`/`exit()` from library code. Library code throws
  `RoundcubeImapSyncException` (extends `RuntimeException`). The plugin entry class catches it
  and turns it into a Roundcube `output->show_message(..., 'error')`.

### JavaScript style

- Plain ES2017+ in `imapsync.js`. No transpilation, no bundler, no jQuery beyond what
  Roundcube already loads.
- Hook into Roundcube via `rcmail.addEventListener('init', fn)` and `rcmail.register_command`.
- Use `rcmail.http_request` / `rcmail.http_post` for AJAX, not `fetch` — these handle Roundcube
  session tokens and error reporting for us.

### Localization

- All user-facing strings go through `$plugin->gettext('key')` (PHP) or
  `rcmail.gettext('key', 'imapsync')` (JS). Never hard-code English in code paths the user sees.
- Keys live in `localization/en_US.inc` and `localization/de_DE.inc`. Keys are lowercase
  alphanumerics, no underscores in keys unless necessary for readability.
- English is the source of truth. German must stay in sync; if you add a key in English,
  also add a German translation (the user is a German speaker).

### Testing

- **PHPUnit 10+.** Tests live in `tests/`, namespace-free, class names end in `Test`.
- Use `PHPUnit\Framework\TestCase`. Prefer real assertions (`assertSame`, `assertEquals`,
  `assertCount`) over `assertTrue`.
- The sync engine takes its IMAP clients via constructor injection so tests can pass in
  fakes. Tests do **not** open real network sockets. There is a `FakeImapClient` test double
  in `tests/Fakes/FakeImapClient.php` modeling the small subset of `rcube_imap_generic` we
  call.
- Run with `vendor/bin/phpunit` from the repo root. CI runs the same command.

### Security expectations

- **Credentials never get logged.** Not in PHP `error_log`, not in Roundcube's `errors.log`,
  not in the AJAX response, not in `$_SESSION` beyond the lifetime of one sync job.
- After a sync, the source-account password is wiped from session storage. Do not persist it
  to the user preferences table.
- The plugin runs in the user's HTTP session — there is no extra cross-user vector, but treat
  the remote host/port/user inputs as **untrusted**. Validate hostname against a configurable
  allow/deny list (`config.inc.php.dist` ships a permissive default; deployment can tighten).
- Refuse plaintext IMAP unless the admin opts in via `$config['imapsync_allow_insecure'] = true`.
  Default is SSL/TLS or STARTTLS only.

---

## Things to NOT do

- **Don't add a "local → remote" sync path** without a product decision. Half the security
  surface lives on that side.
- **Don't shell out to the `imapsync` Perl binary.** Deployment friction is the reason we
  picked the PHP-native path.
- **Don't write to the user preferences table to persist source credentials.** They live in
  session for one job only.
- **Don't introduce a JS bundler / build step.** Roundcube ships static `.js` files. Match
  that.
- **Don't add `composer` runtime dependencies beyond what Roundcube already provides.** Pull
  in a library only if reimplementing it is unreasonable, and justify it in a commit.
- **Don't widen the plugin's `task`.** It only registers itself for `task=settings`. Adding
  itself to `mail` or `login` enlarges the attack surface for no current benefit.

---

## Documentation freshness (binding pre-commit / PR checklist)

Documentation rot is a real failure mode in this repo: a README that lies, an AGENTS.md that
names a removed file, a PR description that doesn't match what actually landed. Every commit
and every PR/MR description must be verified against the **actual diff** before being
submitted. This is **binding**, not a suggestion. The rule applies equally to AI agents and to
humans. If you are an agent: run these checks in your final pre-commit pass and report the
result — do not skip them.

`README.md` and `AGENTS.md` are first-class deliverables and are kept current at the **same**
priority. A commit that changes user-facing behavior or architecture without updating both is
incomplete.

### What to check before each commit

Run these from the repo root. They are fast — budget ~30 seconds. All `git grep` commands use
`--untracked` so they catch staged AND working-tree-only changes; that's the right scope for a
pre-commit check.

1. **Dangling references in docs.** For every file you removed, every class/function you
   renamed, every config key or locale key you dropped, grep the docs:

   ```bash
   # Replace OLD_SYMBOL with the removed/renamed file, class, function, config key, or locale key.
   git grep --untracked -nF 'OLD_SYMBOL' -- '*.md' '*.dist' ':!dist'
   ```

   Any hit must either be removed or updated in the same commit. Doc that claims a symbol
   still exists when it doesn't is a release blocker.

2. **Config keys are consistent across docs and the dist file.** Every `imapsync_*` key
   mentioned in `README.md` or `AGENTS.md` must exist in `config.inc.php.dist`, and vice
   versa:

   ```bash
   diff \
     <(git grep --untracked -hoE '\bimapsync_[a-z_]+' -- README.md AGENTS.md | sort -u) \
     <(git grep --untracked -hoE '\bimapsync_[a-z_]+' -- config.inc.php.dist | sort -u)
   ```

   No output ⇒ clean. Any line difference is a discrepancy to resolve.

3. **Locale keys exist in BOTH `en_US.inc` and `de_DE.inc`, and English == German set.**
   Every key fetched via `$plugin->gettext('foo')` (PHP) or `rcmail.gettext('foo', 'imapsync')`
   (JS) must be defined in both locale files. The two locale files must declare the **same**
   set of keys (English is the source of truth; German must mirror it).

   ```bash
   # Keys used in code (literal-string calls only; dynamic gettext($var) calls are skipped).
   git grep --untracked -hoE 'gettext\([^,)]+' -- '*.php' '*.js' \
     | sed -E "s/gettext\\(['\"]([a-z_]+)['\"].*/\\1/" \
     | grep -E '^[a-z_]+$' | sort -u > /tmp/keys.used

   # Keys defined per locale.
   for f in localization/*.inc; do
       grep -hoE "labels\['[a-z_]+'\]" "$f" \
         | sed -E "s/.*\['([^']+)'\]/\\1/" | sort -u > "/tmp/keys.$(basename $f)"
   done

   comm -23 /tmp/keys.used /tmp/keys.en_US.inc   # keys used in code but missing from en
   comm -23 /tmp/keys.used /tmp/keys.de_DE.inc   # keys used in code but missing from de
   comm -23 /tmp/keys.en_US.inc /tmp/keys.de_DE.inc   # in en, not de
   comm -13 /tmp/keys.en_US.inc /tmp/keys.de_DE.inc   # in de, not en
   ```

   All four `comm` outputs must be empty. The first two catch broken `gettext` calls; the
   last two catch translation drift.

4. **README claims match reality, AGENTS.md architecture matches reality.** Walk the diff:

   - **User-facing change** (UI element, AJAX action, config key, install/usage step,
     dependency, supported skin) → the matching paragraph in `README.md` must move in the
     same commit. If you removed a feature, the README must no longer advertise it.
   - **Architectural or convention change** (engine entry point, file layout, naming rule,
     session/locking behavior, things-not-to-do, sync direction, execution model) →
     `AGENTS.md` must move in the same commit. If you lifted a limit, strike it from "Open
     work / known limits"; if you introduced one, add it.

5. **Repository layout block in AGENTS.md still matches.** If the diff adds, removes, or
   relocates a top-level file/directory, the ASCII tree in `AGENTS.md`'s "Repository layout"
   section must be updated.

### What to check before opening / updating a PR or MR

- The PR description must summarize the **final diff**, not the original plan. If scope
  shifted during implementation, edit the description to match what actually landed.
- Breaking changes (removed config keys, changed defaults, schema changes, removed locale
  keys) must be called out explicitly under a "Breaking changes" subheading.
- For user-facing changes the description must include a short "How to verify" section a
  reviewer can run without reading the code first.
- If the PR moved an item in `AGENTS.md`'s "Open work / known limits" (added or struck), the
  description must mention it — it's a roadmap signal, not implementation noise.

### CI enforcement

GitHub Actions (`.github/workflows/ci.yml`) runs the deterministic subset of these checks
(config-key consistency, locale-key consistency) on every push and PR as the `docs-freshness`
job, alongside `unit-tests`, `integration-tests`, and PHP linting. A failing freshness check
blocks the PR. CI is a safety net, not a substitute — run the checks locally before pushing so
you don't burn a round trip on something a 30-second grep would have caught.

---

## How to verify locally

Plug the plugin into a real Roundcube install:

```bash
# from a working Roundcube checkout
cd /path/to/roundcubemail
ln -s /path/to/Roundcube-IMAPsync plugins/imapsync
# then enable in config/config.inc.php
#   $config['plugins'] = ['...', 'imapsync'];
```

Then open Roundcube → Settings → IMAP sync.

Test suite:

```bash
composer install
vendor/bin/phpunit               # unit tests (fast, no network, no Docker)
composer test:integration        # integration tests (requires a running Docker daemon)
```

The integration suite spins up two real Dovecot containers via testcontainers-php and
exercises the real `rcube_imap_generic` path; it skips itself cleanly when Docker is not
available.

---

## Open work / known limits

- Synchronous execution model — see `### Execution model` above. Large mailboxes WILL hit
  timeouts. A background-worker mode is the planned next iteration.
- No mid-run progress in the UI. The browser shows "loading" until the sync finishes and then
  renders the full summary. Exposing live progress requires an out-of-session store (see
  "Session locking") plus a polling endpoint that reads from it; both are deferred to the
  worker-mode rewrite.
- No incremental / resumable sync yet. A second run with the same source will re-walk every
  folder, relying on the destination's UID-dedup logic to skip already-copied messages —
  correct but slow.
- No support for the IMAP `CONDSTORE` / `QRESYNC` extensions yet. They would make incremental
  sync cheap.
- No quota-aware throttling. If the destination account is near quota, the sync will produce
  a flood of `OVERQUOTA` errors instead of stopping gracefully.
- Skin support: ships an `elastic` skin only. The legacy `classic` skin is not targeted.
- A separate integration suite exists for the real `RoundcubeImapSyncGenericClient`; it runs
  against Dovecot containers via `composer test:integration`.
