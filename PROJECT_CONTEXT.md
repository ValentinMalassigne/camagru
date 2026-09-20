# Camagru — Technical Specification (for the AI agent)

You are building **Camagru** (école 42 web project): a small web app to take webcam/uploaded photos, superimpose PNG overlays server-side, and share them in a public gallery with likes and comments.

This spec is authoritative. Where it conflicts with your habits (frameworks, npm packages, Composer libraries), **the spec wins**. If something is ambiguous or you want to deviate, ask before doing it. The subject's constraints (section 1) are pass/fail at evaluation.

---

## 0. Working rules for the agent

- You can find the working rules for the agent in the `AGENTS.md` file.

## 1. Hard constraints (from the subject)

- **Server:** PHP, **standard library only**. Every function used must exist in the PHP standard library. **No Composer packages, no PHPMailer, no framework, no ORM, no template engine.**
- **Client:** HTML, CSS, vanilla JavaScript with **browser-native APIs only**. No JS libraries, no TypeScript, no bundler, no npm at runtime.
- **CSS:** Tailwind is allowed only as plain compiled CSS (standalone CLI, no Node). **No Tailwind CDN script, no JS plugins, no component libs that ship JS.**
- **Console cleanliness:** the app must produce **no errors, warnings or log lines in any console**, client side (browser console) and server side (container output). Only `getUserMedia` errors on non-HTTPS are tolerated.
- **Security:** no plaintext passwords, no HTML/JS injection, no SQL injection, no unwanted file upload, no forged/foreign-form actions on private data.
- **Deployment:** one command (`docker compose up --build`) must bring the whole site up from a fresh clone.
- **Secrets:** all credentials live in a git-ignored `.env`. Commit only `.env.example` (blank values). Never hardcode secrets.
- Browsers: current Firefox and Chrome. Serve on `http://localhost:8080` (`getUserMedia` works on localhost without HTTPS).

## 2. Stack

| Concern | Choice |
|---|---|
| Language | PHP 8.x (php-fpm), native, small MVC |
| Images | GD, server side only (compositing, re-encoding) |
| Database | PostgreSQL via PDO (`pdo_pgsql`), prepared statements only |
| Web server | nginx + php-fpm |
| Client | HTML, vanilla JS (`getUserMedia`, `fetch`, `FormData`, canvas for preview) |
| CSS | Tailwind, compiled to plain CSS by the standalone CLI |
| Email | Wrapper class (section 6): phase 1 `mail()` + msmtp, phase 2 own SMTP client |
| Containers | docker-compose: `nginx`, `php`, `db`, one-shot `tailwind` |

**Not used, do not add:** Mailpit/MailHog or any dev mail service, PHPMailer, Composer, React/Next/Nest, TypeScript, any JS or PHP dependency.

## 3. Repository layout

```
camagru/
├── docker-compose.yml
├── .env.example            # committed, blank values
├── .env                    # git-ignored
├── .gitignore
├── docker/
│   ├── nginx/default.conf
│   ├── php/{Dockerfile, php.ini, entrypoint.sh}
│   └── postgres/init.sql   # schema, runs on first start
├── config/overlays.php     # whitelist: overlay id => filename
├── public/                 # the ONLY nginx web root
│   ├── index.php           # front controller
│   └── assets/{css/app.css (built), js/*.js, overlays/*.png}
├── src/
│   ├── bootstrap.php       # env, error handling, session, autoloader
│   ├── routes.php
│   ├── Core/               # Router, Request, Response, View, Database, Session, Csrf, Validator, Env
│   ├── Controllers/        # Auth, Gallery, Editor, Image, Like, Comment, Account
│   ├── Models/             # User, Image, Comment, Like, PasswordReset
│   ├── Services/
│   │   ├── ImageComposer.php
│   │   └── Mail/           # Mailer (interface), MsmtpMailer, SmtpMailer, MailerFactory, MessageBuilder, AppMailer
│   └── Views/              # layout, pages, partials, emails
└── tailwind/input.css
```

Rules:
- Autoloading via `spl_autoload_register` (namespace `App\`, PSR-4-style layout). No Composer.
- **Controllers are thin**; **Models** hold all PDO queries; **Views** contain markup only and escape via an `e()` helper. No SQL in controllers/views.
- Only `public/` is web-exposed. `src/`, `config/`, `.env` are never reachable.

## 4. Features to implement (mandatory part)

### 4.1 Common
- Layout: header, main, footer on every page. Responsive (mobile-first with Tailwind). Header shows login/register or logout + editor + account.
- All forms validated **server-side** (client-side hints optional).
- Flash messages for feedback (stored in session, escaped on output).

### 4.2 Users
- **Register:** email (`FILTER_VALIDATE_EMAIL`), username (3–20 chars, `[A-Za-z0-9_]`), password (≥ 8 chars, with lowercase, uppercase and digit). Username and email unique, case-insensitive.
- **Email confirmation:** unique link sent by email; account cannot log in until verified.
- **Login** with username + password; generic error on failure (no user enumeration).
- **Password reset:** request by email → single-use token link with expiry (1 h) → set new password. The response never reveals whether an email exists.
- **Logout:** one click on every page (a POST form with CSRF token in the header, never a GET link).
- **Account page:** change username, email, password; toggle "notify me on new comments" (default **true**). Email change should trigger re-verification.

### 4.3 Gallery (public)
- Lists all edited images by `created_at DESC`, **paginated, 6 per page** (`?page=N`, server-side `LIMIT/OFFSET`, validate `page`).
- Visible to everyone; **only logged-in users** can like (toggle) and comment.
- New comment → email to the image author if their preference is on (skip when author comments on their own image). A mail failure must never break the request (log to file, continue).

### 4.4 Editor (authenticated only)
- Unauthenticated access redirects to login with a friendly message.
- Layout: main section (webcam preview, overlay list, capture button) + side section (thumbnails of the user's previous images, each with delete).
- **Capture button disabled until an overlay is selected.**
- **Fallback upload:** if no webcam, the user can upload an image instead.
- The webcam frame is drawn to a canvas, sent as `FormData` (`canvas.toBlob`) with the overlay **id**. Uploaded files go through the **same server pipeline**.
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

All state-changing routes are POST and require a valid CSRF token. Single front controller (`public/index.php`), nginx `try_files $uri /index.php?$query_string`.

## 6. Email architecture (critical: must be swappable)

All email sending goes through one abstraction. **No other code may call `mail()` or open SMTP sockets.**

```php
namespace App\Services\Mail;

interface Mailer {
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool;
}
```

- `MsmtpMailer` (**phase 1, implement now**): builds headers via `MessageBuilder`, calls PHP `mail()`; PHP `sendmail_path` points to `msmtp -t`.
- `SmtpMailer` (**phase 2, later**): pure PHP SMTP client using `stream_socket_client` (implicit TLS on 465 via `ssl://`, or STARTTLS on 587), `AUTH LOGIN`, dot-stuffing, response-code checks, timeouts. Same interface, no other change in the app.
- `MailerFactory::fromEnv()` picks the driver from `MAIL_DRIVER=msmtp|smtp`.
- `MessageBuilder` (shared): strips CR/LF from every header value (header-injection protection), RFC 2047 UTF-8 subject encoding, `MIME-Version`, `Date`, `Message-ID`, `From`, base64 or quoted-printable body, `text/html; charset=UTF-8` (optionally `multipart/alternative`).
- `AppMailer` exposes domain methods used by controllers: `sendVerification()`, `sendPasswordReset()`, `sendCommentNotification()`. Templates live in `src/Views/emails/`. All user content is escaped in HTML emails.

**msmtp setup (phase 1):**
- Install `msmtp` and CA certificates in the php image.
- `docker/php/entrypoint.sh` generates `/etc/msmtprc` from env vars at container start (mode `600`, owned by the php-fpm user), then `exec php-fpm`.
- Config: `tls on`, `tls_trust_file` set to the system CA bundle, `auth on`, `from`, `user`, `password`, and `logfile` pointing to a **file** (never the console). For port 465 use `tls_starttls off`; for 587 use STARTTLS.
- `php.ini`: `sendmail_path = "/usr/bin/msmtp -t"`.
- No separate mail container. Delivery goes through the external SMTP relay only.

## 7. Database (PostgreSQL)

`docker/postgres/init.sql` (run automatically on first start). Suggested schema:

```sql
users(id SERIAL PK, username TEXT NOT NULL, email TEXT NOT NULL, password_hash TEXT NOT NULL,
      is_verified BOOLEAN NOT NULL DEFAULT false, verification_token_hash TEXT,
      notify_on_comment BOOLEAN NOT NULL DEFAULT true, created_at TIMESTAMPTZ NOT NULL DEFAULT now());
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

- **Overlays:** at least 4 PNGs **with alpha channel** in `public/assets/overlays/`, listed in `config/overlays.php` (`id => filename`). The client sends only the **id**; the server maps it to a file (never accept paths: path-traversal protection).
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

## 10. Docker / environment

`docker compose up --build` from a fresh clone must work, with no manual host-side permission fixes.

- `php`: built from a PHP 8.x fpm image with `gd` (jpeg, png, freetype), `pdo_pgsql`, `msmtp`, CA certificates; custom `entrypoint.sh` and `php.ini`.
- `nginx`: static files from `public/`, PHP via fastcgi to `php`, security headers, `/uploads/` alias, only this service publishes a port (`8080:80`).
- `db`: `postgres` official image, named volume for data, `init.sql` mounted into `/docker-entrypoint-initdb.d`, healthcheck; `php` waits for it.
- `tailwind`: one-shot service running the standalone CLI (`tailwindcss -i tailwind/input.css -o public/assets/css/app.css --minify`); `nginx` waits for `service_completed_successfully`.
- Uploads live in a **named volume** shared by `php` (rw) and `nginx` (ro).
- **Console silence:** `display_errors=Off`, `log_errors=On` with `error_log` to a file; php-fpm access log off; nginx `access_log off` and errors to a file; quiet Postgres logging (`log_min_messages` raised). App code must still be warning-free (`error_reporting = E_ALL` in dev).
- Central exception/error handler: log to file, show a generic 500 page.

`.env.example` (blank values):

```
APP_URL=http://localhost:8080
POSTGRES_DB= POSTGRES_USER= POSTGRES_PASSWORD=
DB_HOST=db  DB_PORT=5432  DB_NAME=  DB_USER=  DB_PASSWORD=
MAIL_DRIVER=msmtp
MAIL_FROM=contact@valentinmalassigne.fr
MAIL_FROM_NAME=Camagru
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=465
SMTP_SECURE=ssl        # ssl | starttls
SMTP_USER=contact@valentinmalassigne.fr
SMTP_PASS=
```

`.gitignore`: `.env`, uploaded files, log files, built CSS.

## 11. Frontend notes

- Editor JS uses `navigator.mediaDevices.getUserMedia`, draws the video to a `<canvas>`, sends the frame with `fetch` + `FormData`. Handle refusal/no-webcam gracefully by showing the upload fallback (no console noise for handled cases).
- Overlay selection enables the capture button. Optional live overlay preview is a bonus (server still produces the final image).
- Tailwind only for styling; write semantic HTML, label all inputs, keep the layout usable at 320 px width.

## 12. Bonus (only after the mandatory part is perfect)

AJAXify likes/comments and editor actions; infinite pagination; live overlay preview on the webcam; social sharing; animated GIF rendering (server side).

## 13. Definition of done

- [ ] Fresh clone + `.env` + `docker compose up --build` → working site
- [ ] Zero console output (browser and containers) during normal use
- [ ] Register → email confirmation → login → reset password → change username/email/password → logout on every page
- [ ] Editor is auth-only; capture button disabled until an overlay is chosen; upload fallback works
- [ ] Composition done server side with GD; alpha respected
- [ ] Gallery public, paginated (6/page), ordered by date; like/comment for logged-in users only
- [ ] Comment notification email sent by default, opt-out in account settings
- [ ] Users can delete only their own images
- [ ] All items in section 9 verified (CSRF, XSS, SQLi, uploads, sessions)
- [ ] All emails go through `Mailer`; switching `MAIL_DRIVER` is the only change needed for phase 2
- [ ] No forbidden dependency anywhere (section 1)