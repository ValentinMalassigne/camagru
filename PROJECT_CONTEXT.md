# Camagru — Technical Specification (for the AI agent)

You are building **Camagru** (école 42 web project): a small web app to take webcam/uploaded photos, superimpose PNG overlays server-side, and share them in a public gallery with likes and comments.

This spec is authoritative. Where it conflicts with your habits (frameworks, npm packages, Composer libraries), **the spec wins**. If something is ambiguous or you want to deviate, ask before doing it. The subject's constraints (section 1) are pass/fail at evaluation.

---

## 0. Working rules for the agent

- You can find the working rules for the agent in the `AGENTS.md` file.
- If an instruction in this spec or in `AGENTS.md` is a problem (impossible, unclear,
  contradictory, or a better option exists), ask the user for their opinion before
  continuing. Details are in `AGENTS.md`.

## 1. Hard constraints (from the subject)

- **Server:** PHP, **standard library only**. Every function used must exist in the PHP standard library (see the allowed extensions in section 2). **No Composer packages, no PHPMailer, no framework, no ORM, no template engine.**
- **Client:** HTML, CSS, vanilla JavaScript with **browser-native APIs only**. No JS libraries, no TypeScript, no bundler, no npm at runtime.
- **CSS:** plain hand-written CSS. **No CSS framework of any kind** (Tailwind was dropped because its output needs recent browsers; Bootstrap, Pure.css and the like are not used either), no CSS build step, no component libs that ship JS.
- **Console cleanliness:** the goal is **no errors, warnings or log lines in any console**, client side (browser console) and server side (container output). Only `getUserMedia` errors on non-HTTPS are tolerated. If a startup line from an official container image cannot be silenced, it is accepted (see section 10); nothing emitted while the app runs is accepted.
- **Security:** no plaintext passwords, no HTML/JS injection, no SQL injection, no unwanted file upload, no forged/foreign-form actions on private data.
- **Deployment:** once the git-ignored `.env` has been created by hand, one command (`docker compose up --build`) must bring the whole site up from a fresh clone. If the database is not set up yet, it is set up automatically (section 10).
- **Secrets:** all credentials and configuration live in a git-ignored `.env`, created by hand. **There is no `.env.example`.** No real value appears in any committed file. Never hardcode secrets.
- **Browsers:** target Firefox >= 41 and Chrome >= 46 (subject minimum), and current versions must work too. The user tests regularly with Firefox 41 and Chrome 46 in a VM, the code follows the defensive coding rules of section 11, and every issue found is logged with its workaround in `COMPATIBILITY.md`. Serve on `http://localhost/` port 80 (`getUserMedia` works on localhost without HTTPS).

## 2. Stack

| Concern | Choice |
|---|---|
| Language | PHP 8.x (php-fpm), native, small MVC |
| Images | GD, server side only (compositing, re-encoding) |
| Database | PostgreSQL via PDO (`pdo_pgsql`), prepared statements only |
| Web server | nginx + php-fpm |
| Client | HTML, vanilla ES5-style JS (`getUserMedia`, `fetch`, `FormData`, canvas), see section 11 |
| CSS | Plain hand-written CSS, mobile-first, flexbox layouts, no framework |
| Email | `mail()` relayed through msmtp (section 6) |
| Containers | docker-compose: `nginx`, `php`, `db` |

**Not used, do not add:** Tailwind, Bootstrap, Pure.css or any other CSS framework, `.env.example`, Mailpit/MailHog or any dev mail service, PHPMailer, Composer, React/Next/Nest, TypeScript, any JS or PHP dependency.

**Allowed PHP extensions and tools** (anything else: stop and ask first):

| Name | Kind | Used for |
|---|---|---|
| PHP core, `standard`, `SPL`, `session`, `hash`, `filter`, `json`, `pcre` | built in | autoload, sessions, hashing, validation, JSON, regex, `mail()`, sockets |
| `gd` | bundled extension | decoding, compositing and PNG re-encoding of images |
| `fileinfo` (`finfo`) | bundled extension | real MIME type detection of uploads |
| `pdo` + `pdo_pgsql` | bundled extensions | database access |
| `mbstring` | bundled extension | UTF-8 string length checks (comments) |
| `msmtp` | external binary, not PHP | relays `mail()` to the SMTP server |

Each bundled extension must be present in the php image (check with `php -m`, enable in the Dockerfile if missing).

## 3. Repository layout

```
camagru/
├── docker-compose.yml
├── .env                    # git-ignored, created by hand, never committed
├── .gitignore
├── NOTES.md                # justification of non-obvious tools, accepted startup lines
├── COMPATIBILITY.md        # living log of features that caused issues on Firefox 41 / Chrome 46, with workarounds
├── docker/
│   ├── nginx/default.conf
│   └── php/{Dockerfile, php.ini, entrypoint.sh}
├── db/schema.sql           # idempotent schema (CREATE ... IF NOT EXISTS)
├── bin/setup-db.php        # CLI script run by entrypoint.sh: waits for the DB, creates what is missing
├── config/overlays.php     # whitelist: overlay id => filename
├── public/                 # the ONLY nginx web root
│   ├── index.php           # front controller
│   ├── favicon.ico
│   └── assets/{css/app.css, js/*.js, overlays/*.png}
└── src/
    ├── bootstrap.php       # env, error handling, session, autoloader
    ├── routes.php
    ├── Core/               # Router, Request, Response, View, Database, Session, Csrf, Validator, Env
    ├── Controllers/        # Auth, Gallery, Editor, Image, Like, Comment, Account
    ├── Models/             # User, Image, Comment, Like, PasswordReset
    ├── Services/
    │   ├── ImageComposer.php
    │   └── Mail/           # MsmtpMailer, MessageBuilder, AppMailer
    └── Views/              # layout, pages, partials, emails
```

Rules:
- Autoloading via `spl_autoload_register` (namespace `App\`, PSR-4-style layout). No Composer.
- **Controllers are thin**; **Models** hold all PDO queries; **Views** contain markup only and escape via an `e()` helper. No SQL in controllers/views.
- Only `public/` is web-exposed. `src/`, `config/`, `db/`, `bin/`, `.env` are never reachable.

## 4. Features to implement (mandatory part)

### 4.1 Common
- Layout: header, main, footer on every page. Responsive (mobile-first, plain CSS media queries). Header shows login/register or logout + editor + account.
- All forms validated **server-side** (client-side hints optional).
- Flash messages for feedback (stored in session, escaped on output).
- A favicon is served on every page (see section 11).

### 4.2 Users
- **Register:** email (`FILTER_VALIDATE_EMAIL`), username (3–20 chars, `[A-Za-z0-9_]`), password (>= 8 chars, with lowercase, uppercase and digit). Username and email unique, case-insensitive.
- **Email confirmation:** unique link sent by email; account cannot log in until verified.
- **Login** with username + password; generic error on failure (no user enumeration).
- **Password reset:** request by email → single-use token link with expiry (1 h) → set new password. The response never reveals whether an email exists.
- **Logout:** one click on every page (a POST form with CSRF token in the header, never a GET link).
- **Account page:** change username, email, password (same validation as registration). An email change takes effect immediately, with no re-verification. Two notification toggles:
  - "notify me on new comments" (default **true**);
  - "also notify me when I comment on my own images" (default **false**).
- **Delete account (addition requested by the user, not part of the école 42 subject):**
  a fourth form on the account page that requires the current password
  (re-authentication). Instant effect: the user row is deleted — images, likes,
  comments and reset tokens follow via `ON DELETE CASCADE` — and the session is
  fully destroyed. Uploaded image files are removed by the application in
  phase 3 (a DB cascade cannot touch the filesystem).

### 4.3 Gallery (public)
- Lists all edited images by `created_at DESC`, **paginated, 6 per page** (`?page=N`, server-side `LIMIT/OFFSET`, validate `page`).
- Visible to everyone; **only logged-in users** can like (toggle) and comment.
- New comment → email to the image author if their "notify me on new comments" preference is on. When the author comments on their own image, the email is sent only if they also turned on "also notify me when I comment on my own images" (default off; it has no effect while the first toggle is off). A mail failure must never break the request (log to file, continue).

### 4.4 Editor (authenticated only)
- Unauthenticated access redirects to login with a friendly message.
- Layout: main section + side section.
  - **Main section:** webcam preview, overlay list, capture button, **and** a file upload form. The webcam and the upload are both always available; the user picks either one.
  - **Side section:** thumbnails of **all the previous pictures taken by the current user** (only their own, newest first), each with a delete button.
- **The capture button and the upload submit are disabled until an overlay is selected.**
- If there is no webcam, permission is refused or `getUserMedia` is unavailable, a friendly message replaces the preview and the upload keeps working.
- The webcam frame is drawn to a canvas, exported with `canvas.toDataURL('image/png')`, converted to a Blob and sent as `FormData` with the overlay **id** (`canvas.toBlob` is not used, see section 11). Uploaded files go through the **same server pipeline**.
- **Compositing happens on the server** (GD): resize/scale the overlay to the base image, alpha-blend, save as PNG.
- Users can delete **only their own** images (server-side ownership check, POST + CSRF). Deleting removes the file and DB rows (likes/comments cascade).

## 5. Routes

```
GET  /                       gallery (?page=)
GET  /images/{id}            image detail (likes, comments)
GET  /register      POST /register
GET  /verify?token=
GET  /login         POST /login
POST /logout
GET  /forgot-password   POST /forgot-password
GET  /reset-password?token=   POST /reset-password
GET  /account       POST /account
GET  /editor                 (auth)
POST /editor/capture         (auth, CSRF) -> JSON {id, url}
POST /images/{id}/delete     (auth, owner, CSRF)
POST /images/{id}/like       (auth, CSRF)
POST /images/{id}/comments   (auth, CSRF)
```

All state-changing routes are POST and require a valid CSRF token. Single front controller (`public/index.php`), nginx `try_files $uri /index.php?$query_string`. Static files (`/favicon.ico`, `/assets/`, `/uploads/`) are served by nginx directly.

## 6. Email architecture

All email sending goes through two classes. **No other code may call `mail()`.**

- `MsmtpMailer`: builds the MIME message via `MessageBuilder`, calls PHP `mail()`; PHP `sendmail_path` points to `msmtp -t`, which relays the message to the external SMTP server. There is no own SMTP client in PHP (user decision: the originally planned phase-2 SMTP client was dropped; delivery is always relayed by msmtp).
- `MessageBuilder` (shared): strips CR/LF from every header value (header-injection protection), RFC 2047 UTF-8 subject encoding, `MIME-Version`, `Date`, `Message-ID`, `From`, base64 or quoted-printable body, `text/html; charset=UTF-8` (optionally `multipart/alternative`).
- `AppMailer` exposes domain methods used by controllers: `sendVerification()`, `sendPasswordReset()`, `sendCommentNotification()`. Templates live in `src/Views/emails/`. All user content is escaped in HTML emails.

**msmtp setup:**
- Install `msmtp` and CA certificates in the php image.
- `docker/php/entrypoint.sh` generates `/etc/msmtprc` from env vars at container start (mode `600`, owned by the php-fpm user), then runs the DB setup (section 10), then `exec php-fpm`.
- Config: `tls on`, `tls_trust_file` set to the system CA bundle, `auth on`, `from`, `user`, `password`, and `logfile` pointing to a **file** (never the console). For port 465 use `tls_starttls off`; for 587 use STARTTLS.
- `php.ini`: `sendmail_path = "/usr/bin/msmtp -t"`.
- No separate mail container. Delivery goes through the external SMTP relay only.

## 7. Database (PostgreSQL)

`db/schema.sql` holds the schema. It must be **idempotent** (`CREATE TABLE IF NOT EXISTS`, `CREATE UNIQUE INDEX IF NOT EXISTS`), because it can run on every start (section 10). Suggested schema:

```sql
users(id SERIAL PK, username TEXT NOT NULL, email TEXT NOT NULL, password_hash TEXT NOT NULL,
      is_verified BOOLEAN NOT NULL DEFAULT false, verification_token_hash TEXT,
      notify_on_comment BOOLEAN NOT NULL DEFAULT true,
      notify_on_own_comment BOOLEAN NOT NULL DEFAULT false,
      created_at TIMESTAMPTZ NOT NULL DEFAULT now());
  -- UNIQUE INDEX on lower(username) and lower(email)
password_resets(id SERIAL PK, user_id INT REFERENCES users ON DELETE CASCADE,
      token_hash TEXT NOT NULL, expires_at TIMESTAMPTZ NOT NULL, used_at TIMESTAMPTZ);
images(id SERIAL PK, user_id INT REFERENCES users ON DELETE CASCADE,
      filename TEXT NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT now());
  -- INDEX on created_at DESC
likes(user_id INT REFERENCES users ON DELETE CASCADE, image_id INT REFERENCES images ON DELETE CASCADE,
      PRIMARY KEY (user_id, image_id));
comments(id SERIAL PK, image_id INT REFERENCES images ON DELETE CASCADE,
      user_id INT REFERENCES users ON DELETE CASCADE, body TEXT NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT now());
```

PDO settings: `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES = false`, `FETCH_ASSOC`. **Every query uses bound parameters**, including `LIMIT/OFFSET`. Store **hashes** of verification/reset tokens (tokens generated with `random_bytes(32)`, compared with `hash_equals`). The DB port is not published to the host.

## 8. Images

- **Overlays:** at least 4 PNGs **with alpha channel** in `public/assets/overlays/`, listed in `config/overlays.php` (`id => filename`). The client sends only the **id**; the server maps it to a file (never accept paths: path-traversal protection). A missing or unknown id is rejected, for both the webcam capture and the upload.
- **Upload/capture pipeline (single code path):**
  1. Check size (max 5 MB) and upload errors.
  2. Detect real type with `finfo` (allow only `image/png`, `image/jpeg`) and `getimagesize`; cap dimensions.
  3. Decode with GD (`imagecreatefromstring`), **re-encode** to PNG (strips metadata and any embedded payload).
  4. Composite overlay (`imagealphablending`, `imagesavealpha`, `imagecopyresampled`/`imagecopy`).
  5. Save under a random filename (`bin2hex(random_bytes(16)).png`) in the uploads volume; store filename only.
- Uploads are served by nginx at `/uploads/` from a volume mounted **read-only** in nginx; **PHP execution is disabled** there.
- Body/upload limits aligned: nginx `client_max_body_size`, PHP `upload_max_filesize` and `post_max_size`.

## 9. Security requirements

- **Passwords:** `password_hash(PASSWORD_DEFAULT)` / `password_verify`; rehash if needed.
- **CSRF:** per-session token from `random_bytes`, checked with `hash_equals` on every POST (form field or `X-CSRF-Token` header for `fetch`).
- **XSS:** escape all output with `e()` = `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Pass data to JS via `data-*` attributes, no inline `<script>` or inline event handlers.
- **SQL injection:** prepared statements only.
- **Sessions:** `session.use_strict_mode=1`, `use_only_cookies=1`, cookie `HttpOnly` + `SameSite=Lax` (+ `Secure` when served over HTTPS), `session_regenerate_id(true)` on login, full destroy on logout.
- **Authorization:** every private action checks authentication and ownership server-side.
- **Headers (nginx):** `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, and a CSP like `default-src 'self'; img-src 'self' blob: data:; media-src 'self' blob:`. Use `server_tokens off`.
- **Header injection:** sanitize CR/LF in anything placed in email headers.
- Nice to have: basic login throttling.

**Final security review (mandatory, at the end of the mandatory part).** The agent reminds the user to do it, and the mandatory part is not finished until it is done. Checklist:
- No plaintext password anywhere (DB, logs, emails).
- No HTML/JS injection: every output escaped, including flash messages, usernames, comments, emails.
- Uploads: size, real type, re-encoding, random filename, no PHP execution in `/uploads/`.
- No SQL built by string concatenation; `LIMIT/OFFSET` bound too.
- Every POST checks the CSRF token; every private route checks authentication and ownership.
- Sessions and headers configured as above; tokens are random, hashed in DB, single-use and expiring where required.
- No real secret in any committed file, and `.env` is git-ignored.
- Error pages and logs leak no internal detail (paths, queries, stack traces).

## 10. Docker / environment

Once `.env` exists, `docker compose up --build` from a fresh clone must work, with no other manual step and no manual host-side permission fixes.

- `php`: built from a PHP 8.x fpm image with `gd` (jpeg, png, freetype), `pdo_pgsql`, `msmtp`, CA certificates; custom `entrypoint.sh` and `php.ini`. The entrypoint (1) generates `/etc/msmtprc`, (2) runs `php bin/setup-db.php`, (3) `exec php-fpm`.
- `nginx`: static files from `public/`, PHP via fastcgi to `php`, security headers, `/uploads/` alias, only this service publishes a port (`80:80`).
- `db`: `postgres` official image, named volume for data, healthcheck (`pg_isready`), no published port; `php` waits for it (`depends_on` with `service_healthy`). No `init.sql` mounted: the schema is handled by the setup script below.
- Uploads live in a **named volume** shared by `php` (rw) and `nginx` (ro).

**Automatic database setup (`bin/setup-db.php`).** If the database is not set up, the project must set it up by itself:
1. Wait until the DB accepts connections (bounded number of retries, then fail).
2. Check that the required tables exist.
3. If any is missing, apply `db/schema.sql` (idempotent, so it also repairs a partial setup and is safe on every start).
4. Never drop, truncate or overwrite existing data.
5. Print nothing on success. On failure, log to the file and exit non-zero so the container does not serve a broken site.

**Console silence.**
- Runtime: `display_errors=Off`, `log_errors=On` with `error_log` to a file; php-fpm access log off; nginx `access_log off` and errors to a file; Postgres logging quiet (`log_min_messages` raised). App code must still be warning-free (`error_reporting = E_ALL` in dev). Central exception/error handler: log to file, show a generic 500 page.
- Startup: try to silence the startup output of each image (levers to try: php-fpm `log_level`, `NGINX_ENTRYPOINT_QUIET_LOGS`, Postgres `log_min_messages`). A startup line that still cannot be silenced (for example the Postgres init banner on first start) is accepted, and must be listed in `NOTES.md` with its source. Nothing emitted at request time is accepted.

**Environment variables** (`.env`, git-ignored, created by hand; names only, no value is ever written in a committed file):

| Variable | Purpose | Secret |
|---|---|---|
| `APP_URL` | public base URL used in email links (`http://localhost` locally) | no |
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | database created by the postgres image | password |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | connection settings used by php (`DB_HOST` is the compose service name; `DB_NAME/USER/PASSWORD` must match `POSTGRES_*`) | password |
| `MAIL_FROM`, `MAIL_FROM_NAME` | sender address and name | no |
| `SMTP_HOST`, `SMTP_PORT` | SMTP relay (465 with `ssl`, 587 with `starttls`) | no |
| `SMTP_SECURE` | `ssl` or `starttls` | no |
| `SMTP_USER`, `SMTP_PASS` | SMTP login | password |

`.gitignore`: `.env`, uploaded files, log files.

## 11. Frontend notes

- Editor JS gets the webcam through the small wrapper described in the compatibility rules below (never by calling `getUserMedia` directly), draws the video to a `<canvas>`, sends the frame with `fetch` + `FormData`. The upload form is always visible next to the webcam. Handle refusal/no-webcam gracefully by showing a message in place of the preview (no console noise for handled cases).
- Overlay selection enables the capture button and the upload submit. Optional live overlay preview is a bonus (server still produces the final image).
- CSS lives in `public/assets/css/app.css`: plain hand-written CSS, semantic HTML, label all inputs, keep the layout usable at 320 px width.
- **Favicon:** `public/favicon.ico` exists and is referenced by `<link rel="icon">` in the layout head, so the browser console never shows a 404 for it.

### Compatibility: Firefox 41 and Chrome 46

**How it is tested.** The user runs Firefox 41 and Chrome 46 in a VM and tests the project with them regularly: at the end of every feature, and once more in a full pass at the end (after the security review, before any bonus). The agent cannot run these browsers, so it never claims compatibility. After each feature it gives the user a short smoke-test list (pages load, narrow layout, clean console, the feature's flow) and waits for the result. Prefer a port forward so the VM sees the site as `http://localhost` (a `getUserMedia` refusal on a non-localhost HTTP origin is tolerated, but the webcam path then cannot be validated).

**Defensive coding rules** (a safe subset; `COMPATIBILITY.md` refines it as issues are found):
- JS is written in ES5 style: `var` and `function`; no arrow functions, template literals, `let` or `class`, and no newer syntax (spread, destructuring, `async/await`, optional chaining).
- Webcam access goes through one small wrapper: use `navigator.mediaDevices.getUserMedia` when it exists, otherwise the prefixed `navigator.getUserMedia` / `webkitGetUserMedia` / `mozGetUserMedia` with callbacks. If none exists, show the upload-only message.
- Attach the stream to the `<video>` by feature detection: `srcObject`, else `mozSrcObject`, else `video.src = URL.createObjectURL(stream)`.
- Do not use `canvas.toBlob`. Use `toDataURL('image/png')` and convert the result to a Blob with `atob`, `Uint8Array` and `Blob`, so there is a single code path.
- CSS: flexbox layouts only; no CSS grid, no custom properties (`var()`), no flex `gap`, no `position: sticky`, no `aspect-ratio`.
- Any other browser API, syntax or CSS feature outside this safe subset: read `COMPATIBILITY.md` first, and ask if in doubt.
- Optional: linters (`es-check`, `eslint-plugin-compat`, `doiuse`, target `firefox 41, chrome 46`) may be run from a throwaway container outside the repo. They are never added to the project.

**`COMPATIBILITY.md` (living log).** Every function, API, syntax or CSS feature we tried that caused an issue on Firefox 41 or Chrome 46 gets an entry with its workaround, so the same problem is never hit twice.
- Columns: feature, browser(s), symptom, workaround, status, files using it. Status is `expected` (known from documentation, not yet seen in the VM), `confirmed` (reproduced in the VM) or `fixed` (workaround applied and re-tested in the VM).
- When the user reports a compatibility problem, the agent applies the workaround and adds the entry. Entries are never deleted, only updated.
- The agent reads the file before writing client code and reuses the workarounds.
- The file starts with the three known risks above (`srcObject`, `getUserMedia`, `toBlob`) as `expected` entries.

## 12. Bonus (only after the mandatory part is perfect)

AJAXify likes/comments and editor actions; infinite pagination; live overlay preview on the webcam; social sharing; animated GIF rendering (server side).

## 13. Definition of done

- [ ] Fresh clone + hand-made `.env` + `docker compose up --build` → working site
- [ ] Empty database → schema created automatically; already set up → untouched, no data loss
- [ ] Zero console output (browser and containers) during normal use; only unavoidable startup lines, each listed in `NOTES.md`
- [ ] Favicon served, no 404 in the browser console
- [ ] Register → email confirmation → login → reset password → change username/email/password → logout on every page
- [ ] Editor is auth-only; capture button and upload disabled until an overlay is chosen; both webcam capture and file upload available and working; sidebar shows all the current user's previous pictures
- [ ] Composition done server side with GD; alpha respected
- [ ] Gallery public, paginated (6/page), ordered by date; like/comment for logged-in users only
- [ ] Comment notification email sent by default, opt-out in account settings; self-comment notification option (default off) works
- [ ] Users can delete only their own images
- [ ] Final security review done: all items in section 9 verified (CSRF, XSS, SQLi, uploads, sessions)
- [ ] All emails go through `AppMailer`/`MsmtpMailer`; no other code calls `mail()`
- [ ] No forbidden dependency anywhere (section 1), no CSS framework, no `.env.example`, `.env` git-ignored, no real value in any committed file
- [ ] Firefox 41 and Chrome 46 smoke-tested by the user in the VM after each feature, plus a full final pass on every page and flow
- [ ] `COMPATIBILITY.md` up to date: every issue found has its workaround and status
- [ ] Client code follows the defensive coding rules of section 11 (ES5-style JS, webcam wrapper, no `canvas.toBlob`, flexbox-only CSS)