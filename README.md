# Roundcube IMAPsync

A Roundcube plugin that pulls mail from a remote IMAP server into the currently logged-in
Roundcube account. Built for the one-shot account-migration case: you sit in your new mailbox,
enter your old server's credentials, click Start, and watch your folders and messages stream in.

The synchronization runs natively in PHP on top of Roundcube's own `rcube_imap_generic` client.
There is no Perl `imapsync` binary, no background daemon, no extra runtime dependency.

> **Status:** MVP. Synchronous execution suitable for small to medium mailboxes. Background-worker
> mode for large migrations is on the roadmap — see [Known limitations](#known-limitations).

---

## Features

- **Settings-section UI.** A new "IMAP sync" entry appears in Roundcube's Settings; no extra
  menus or buttons clutter the rest of the interface.
- **PHP-native sync.** Walks every folder on the source, recreates missing folders on the
  destination preserving hierarchy, and appends only the messages that are not already there.
- **Idempotent dedup.** A second run against the same source skips everything already copied,
  using `Message-ID + size` (with a subject/date/size fallback for messages that lack a
  `Message-ID`).
- **Result summary.** When the sync finishes, the page shows folders synced, messages copied,
  skipped, and any per-message errors. (Live mid-run progress is intentionally not exposed in
  this MVP — see [Known limitations](#known-limitations).)
- **TLS by default.** Plaintext IMAP is refused unless an administrator opts in via config.
- **Host allow/deny lists.** Administrators can restrict which remote servers users can sync
  from.
- **No password persistence.** Source credentials live in the PHP session for one job and are
  wiped immediately after the sync returns.

---

## Requirements

- **Roundcube 1.6.0 or newer** (tested against 1.7.0).
- **PHP 8.1+** with the `imap` extension available — Roundcube already needs it, so this is a
  given on any working Roundcube install.
- Composer (only required if you want to run the test suite or pull dev dependencies).

---

## Installation

### Drop-in release tarball (recommended)

Every tagged release publishes a ready-to-unpack tarball and zip on the
[GitHub Releases page](https://github.com/Hermsi1337/Roundcube-IMAPsync/releases).
The archive root is `imapsync/`, so it extracts straight into Roundcube's
`plugins/` directory — no composer, no git, no extra steps.

```bash
cd /path/to/roundcubemail/plugins
# Replace v0.1.0 with the latest tag from the Releases page.
curl -L -o imapsync.tar.gz \
  https://github.com/Hermsi1337/Roundcube-IMAPsync/releases/download/v0.1.0/roundcube-imapsync-0.1.0.tar.gz
tar -xzf imapsync.tar.gz
rm imapsync.tar.gz
```

A SHA-256 checksum file is published alongside each archive — verify it with
`sha256sum -c roundcube-imapsync-<version>.sha256` if you care about supply-chain
integrity.

### Via Composer

```bash
cd /path/to/roundcubemail
composer require hermsi1337/imapsync
```

Roundcube's `plugin-installer` will drop the plugin into `plugins/imapsync/`.

### From a git checkout (development)

```bash
cd /path/to/roundcubemail/plugins
git clone https://github.com/Hermsi1337/Roundcube-IMAPsync imapsync
```

### Enabling the plugin

Whichever install path you chose, enable the plugin in
`config/config.inc.php`:

```php
$config['plugins'] = [
    // ... your existing plugins
    'imapsync',
];
```

Optionally copy and edit the plugin configuration:

```bash
cp plugins/imapsync/config.inc.php.dist plugins/imapsync/config.inc.php
```

Reload Roundcube. A new **IMAP sync** entry appears under Settings.

---

## Usage

1. Open Roundcube, log in to the account that should **receive** the mail.
2. Go to **Settings → IMAP sync**.
3. Enter the **source** server's host, port, encryption, username and password.
4. Click **Start sync**. The browser shows a "loading" indicator until the sync finishes.
5. When the sync completes, the page shows a summary (folders synced, messages copied,
   messages skipped, errors).
6. Open your inbox — the migrated folders and messages are there.

### Re-running against the same source

Running the sync twice is safe. The second run sees the messages already in the destination,
detects duplicates by `Message-ID + size`, and skips them. Only new messages on the source are
copied.

### Cancelling

Closing the browser tab does **not** stop the sync — it runs to completion server-side. A
"Cancel" button is on the roadmap but not in this MVP.

---

## Configuration

All settings live in `plugins/imapsync/config.inc.php` (copy from `config.inc.php.dist`). Defaults
are safe to leave alone; override only what you need.

| Key | Type | Default | Description |
|---|---|---|---|
| `imapsync_allow_insecure` | `bool` | `false` | Allow plaintext IMAP as a source encryption choice. **Leave off in production.** |
| `imapsync_host_allowlist` | `string[]` | `[]` | If non-empty, the source host must match one of these patterns (substring match). |
| `imapsync_host_denylist` | `string[]` | `[]` | Source hosts matching any of these patterns are refused. |
| `imapsync_skip_folders` | `string[]` | `[]` | Folder names on the source to skip globally. |
| `imapsync_folder_prefix` | `string` | `''` | Prefix to prepend to destination folder paths (useful when you want all synced folders to land under e.g. `Imported/`). |

---

## Security

- **Passwords are never written to disk.** Source credentials enter the form, get used by the
  sync engine, and are wiped from the PHP session as soon as the engine returns — success or
  failure.
- **No logging of credentials.** Neither PHP's `error_log` nor Roundcube's `errors.log` ever see
  the source password or username in cleartext.
- **TLS is the default.** Plaintext IMAP requires an explicit admin opt-in
  (`imapsync_allow_insecure`).
- **Host gating.** Administrators can pin acceptable source hosts via the allow/deny lists, e.g.
  to confine a migration window to a single legacy server.

If you find a security-relevant issue, please open an issue on GitHub — or, for sensitive
reports, email the maintainer directly.

---

## Development

This repository is the **plugin source**, not a full Roundcube checkout. The `dist/` directory
holds a local copy of the Roundcube release used as a reference while developing (API
signatures, plugin patterns) — it is gitignored.

### Bootstrap

```bash
git clone https://github.com/hermsi1337/Roundcube-IMAPsync
cd Roundcube-IMAPsync
composer install
```

### Running tests

```bash
composer test:unit          # in-memory unit tests, no network, no Docker
composer test:integration   # Dovecot integration suite, requires a running Docker daemon
composer test               # alias for test:unit
```

The unit tests do not touch the network — the sync engine takes an injected IMAP-client
interface (`RoundcubeImapSyncClient`) and the suite uses an in-memory fake
(`tests/Fakes/FakeImapClient.php`).

The integration suite spins up two real Dovecot containers via
[`testcontainers-php`](https://github.com/testcontainers/testcontainers-php) and skips itself
cleanly if Docker is not available.

### Fetching the Roundcube reference tree

```bash
composer dist:fetch
```

Downloads Roundcube 1.7.0 into `dist/` (idempotent — no-op if it's already there). The
`dist/` tree is gitignored and only used as a read-only reference while developing the plugin.

### Conventions

See [`AGENTS.md`](AGENTS.md) for the binding code conventions, naming rules, things-not-to-do
list, and architectural decisions. AI coding assistants (Claude, Codex, etc.) are expected to
read it first; `CLAUDE.md` is a symlink to the same file so any tool that auto-loads project
context picks it up.

### Reference documentation

- Roundcube Plugin API wiki: <https://github.com/roundcube/roundcubemail/wiki/Plugin-API>
- Roundcube plugin index: <https://plugins.roundcube.net/>
- `imapsync` (Perl CLI, behavioral reference only): <https://github.com/imapsync/imapsync>
- Predecessor plugin (unmaintained): <https://github.com/server-gurus/RCimapSync>

---

## Known limitations

- **Synchronous execution.** The sync runs inside the HTTP request that started it. Very large
  mailboxes can hit PHP `max_execution_time` or browser timeouts. A background-worker mode with
  resumable jobs is the planned next iteration.
- **No mid-run progress.** The browser sits on a "loading" indicator until the sync finishes
  and then shows the full summary. Live per-folder progress requires either a worker process
  or out-of-session progress storage; both are planned as part of the worker-mode rewrite.
- **No resume.** A failed sync has to be restarted from the top. Idempotent dedup means already-
  copied messages are skipped fast, but the folder walk still happens.
- **No `CONDSTORE` / `QRESYNC`.** Incremental syncs are correct but not as cheap as they could
  be on servers that support these IMAP extensions.
- **No quota-aware throttling.** If the destination account is close to quota, you will see a
  burst of `OVERQUOTA` errors instead of a clean stop.
- **Elastic skin only.** The legacy `classic` skin is not targeted.

---

## License

[MIT](LICENSE). See the `LICENSE` file at the repo root for the full text.

---

## Acknowledgements

- The original [`RCimapSync`](https://github.com/server-gurus/RCimapSync) plugin by server-gurus —
  the motivation for writing this one (it has been unmaintained for years).
- The [Perl `imapsync`](https://github.com/imapsync/imapsync) tool by Gilles Lamiral — a
  behavioral reference for the trickier IMAP edge cases.
- The Roundcube core team for `rcube_imap_generic`, which makes a PHP-native sync engine
  realistic in the first place.
