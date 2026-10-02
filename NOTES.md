# NOTES.md — Camagru

Justification of non-obvious tools and accepted (un-silenceable) startup lines.

## Non-obvious tools used

- **msmtp** — external binary (not PHP) that relays PHP `mail()` to the SMTP
  relay. Required by spec section 6 for phase 1. Installed in the php image;
  config generated at start by `entrypoint.sh` from `.env`.
- **GD** — bundled PHP extension for decoding, compositing and PNG
  re-encoding of images (server side). Built with freetype + jpeg + png support.
  Used from phase 3 by `ImageComposer`: `imagecreatefromstring` to decode,
  a fresh truecolor canvas to re-encode (stripping any metadata or payload
  embedded in the source), `imagecopyresampled` with alpha blending to draw
  the overlay, `imagepng` to save.
- **pdo_pgsql** — bundled PHP extension for PostgreSQL access via PDO. All
  queries use bound parameters.
- **openssl** — bundled PHP extension; required later for the phase-2 SMTP
  client (`ssl://`, STARTTLS). Present in the image already.
- **fileinfo (`finfo`)** — bundled PHP extension for real MIME detection of
  uploads (used from phase 3 onward): `finfo` reads the uploaded content
  with `FILEINFO_MIME_TYPE`, so a lying extension or filename is ignored.
  Only `image/png` and `image/jpeg` pass; PHP code renamed to `.png` is
  rejected before GD ever decodes it.
- **mbstring** — bundled PHP extension for UTF-8-aware string length checks
  (used from phase 4 onward for comments).

## Console silence — levers tried

- **php-fpm**: `log_level = error` (set on the global `php-fpm.conf` line in the
  Dockerfile) suppresses notice-level startup lines such as "fpm is running"
  and "ready to handle connections". The per-request access log is disabled
  (`access.log = /dev/null` in the `[www]` pool). With both applied, the php
  container prints nothing on start.
- **msmtp (called by PHP `mail()`)**: delivery errors go to stderr, which
  under php-fpm lands in the container console. `sendmail_path` therefore
  ends with `2>/dev/null`; failures are still recorded in msmtp's own log
  file (`/var/log/camagru/msmtp.log` on the logs volume) and `mail()` still
  returns false to the application. The entrypoint chowns the log directory
  to the php-fpm user so that `mail()` (running as www-data) can write it.
- **nginx**: `NGINX_ENTRYPOINT_QUIET_LOGS=1` silences the image's entrypoint
  informational line. The main `error_log` is redirected (via the container
  `command`, which `sed`s `nginx.conf`) to `/var/log/camagru/nginx_error.log`
  at `error` level, suppressing the "start worker process" notices. `access_log
  off`; `server_tokens off` hides the version. With both applied, the nginx
  container prints nothing on start.
- **Postgres**: `log_min_messages=warning` raised so DEBUG/INFO/NOTICE messages
  are suppressed. The remaining startup lines are `LOG` level, which always
  ranks above the threshold and cannot be silenced by this knob (see below).
  The periodic `LOG: checkpoint starting/complete` reports (Postgres 15+
  defaults to `log_checkpoints=on`, one pair every 5 minutes of activity) are
  runtime output, not startup lines, so they had to go too:
  `log_checkpoints=off` is passed on the `db` command line in
  `docker-compose.yml`. Verified: an explicit `CHECKPOINT` produces no console
  line.

## Accepted startup lines

These lines come from the official Postgres image at startup only and cannot
be silenced by `log_min_messages` (Postgres always emits `LOG`-rank messages).
They appear once on start and never again while the app is running.

- `db`: `LOG: starting PostgreSQL 16.15 ...`
- `db`: `LOG: listening on IPv4 address "0.0.0.0", port 5432`
- `db`: `LOG: listening on IPv6 address "::", port 5432`
- `db`: `LOG: listening on Unix socket "/var/run/postgresql/.s.PGSQL.5432"`
- `db`: `LOG: database system was shut down at ...`
- `db`: `LOG: database system is ready to accept connections`
- `db`: (first start only) the initdb banner and `CREATE DATABASE` output from
  the postgres image's first-run initialization. Two lines inside that banner
  deserve their own mention, listed after trying to silence them: `sh: locale:
  not found` followed by `WARNING: no usable system locales were found`.
  Source: initdb (inside the official image) probing for the `locale` tool,
  which Alpine does not ship. Tried and rejected:
  `POSTGRES_INITDB_ARGS="--no-locale"` (the cluster then uses the C locale,
  but initdb still probes and prints both lines, verified on a throwaway
  clone), and rebuilding a custom postgres image with locale tooling is out
  of proportion for two first-start-only lines. Accepted: first start of a
  new volume only, never at request time.
- `db`: `PostgreSQL Database directory appears to contain a database; Skipping
  initialization` — printed by the official image's entrypoint script on
  every start of an existing data volume (not by the server, so
  `log_min_messages` cannot reach it). Startup only; never at request time.

No request-time output is produced by any container.

## Accepted browser console output

These lines are generated by the browser itself, not by the app, and cannot
be avoided without leaving the project's constraints. They are listed here
per the console output policy, with their source.

- **Firefox 41**: "Password fields present on an insecure (http://) page" and
  "Password fields present in a form with an insecure (http://) form action".
  Source: Firefox's own insecure-password heuristic, emitted for every
  `<input type="password">` served over HTTP. The spec serves the site on
  `http://localhost:8080` with no TLS, so the only fix (HTTPS) is out of
  scope. Appears on the register page, and later on every page with a
  password field (login, reset, account). Chrome 46 does not emit it.
  Logged in COMPATIBILITY.md as entry 4.

## Environment variables not in the spec

Added with the user's agreement (spec section 10 lists only APP_URL for
email links); the user creates the value by hand in `.env`:

- **APP_ALLOWED_HOSTS** — comma-separated list of extra hosts that may appear
  as the host of email links (see `src/Core/SiteUrl.php`). Each entry is a
  hostname pattern where `*` covers exactly one dot-separated label
  (e.g. `192.168.*.*`). The host of APP_URL is always allowed; any other
  request Host falls back to APP_URL. This allows testing the site from
  other machines (laptop, school computers) without editing APP_URL, while
  the allowlist prevents host-header poisoning of emailed links.

## Accepted deviations

- **Real mail values in the git history (final security review, user
  decision).** The two initial commits (`dcd8723`, `cccc453`, both on
  `origin/main`) contained the real `MAIL_FROM` address, `SMTP_HOST` and
  `SMTP_USER` in `PROJECT_CONTEXT.md`. Every later revision is clean:
  the working tree contains no real value, `.env` was never committed and is
  git-ignored. The values in the history are the sending address and its
  SMTP host/login — no password — and they appear in every email the app
  sends anyway. Accepted rather than rewriting pushed history: private
  repository, school project. The password itself never leaked.

## Internal overridable constants (no `.env` entry needed)

Same pattern as the pre-existing `APP_LOG_FILE` (see `src/bootstrap.php`):
a getenv override with a working default, so nothing has to be added to
`.env` for the stack to run.

- **APP_UPLOAD_DIR** — directory where the composited pictures are written
  (default `/var/www/camagru/uploads`, the uploads volume shared with
  nginx). Only useful for running the pipeline outside docker.
