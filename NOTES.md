# NOTES.md — Camagru

Justification of non-obvious tools and accepted (un-silenceable) startup lines.

## Non-obvious tools used

- **msmtp** — external binary (not PHP) that relays PHP `mail()` to the SMTP
  relay. Required by spec section 6 for phase 1. Installed in the php image;
  config generated at start by `entrypoint.sh` from `.env`.
- **GD** — bundled PHP extension for decoding, compositing and PNG
  re-encoding of images (server side). Built with freetype + jpeg + png support.
- **pdo_pgsql** — bundled PHP extension for PostgreSQL access via PDO. All
  queries use bound parameters.
- **openssl** — bundled PHP extension; required later for the phase-2 SMTP
  client (`ssl://`, STARTTLS). Present in the image already.
- **fileinfo (`finfo`)** — bundled PHP extension for real MIME detection of
  uploads (used from phase 3 onward).
- **mbstring** — bundled PHP extension for UTF-8-aware string length checks
  (used from phase 4 onward for comments).

## Console silence — levers tried

- **php-fpm**: `log_level = error` (set on the global `php-fpm.conf` line in the
  Dockerfile) suppresses notice-level startup lines such as "fpm is running"
  and "ready to handle connections". The per-request access log is disabled
  (`access.log = /dev/null` in the `[www]` pool). With both applied, the php
  container prints nothing on start.
- **nginx**: `NGINX_ENTRYPOINT_QUIET_LOGS=1` silences the image's entrypoint
  informational line. The main `error_log` is redirected (via the container
  `command`, which `sed`s `nginx.conf`) to `/var/log/camagru/nginx_error.log`
  at `error` level, suppressing the "start worker process" notices. `access_log
  off`; `server_tokens off` hides the version. With both applied, the nginx
  container prints nothing on start.
- **Postgres**: `log_min_messages=warning` raised so DEBUG/INFO/NOTICE messages
  are suppressed. The remaining startup lines are `LOG` level, which always
  ranks above the threshold and cannot be silenced by this knob (see below).

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
  the postgres image's first-run initialization.

No request-time output is produced by any container.
