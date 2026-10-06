# camagru

bonus can be done later : 

to connect to host from VM : ssh -N -L 127.0.0.1:80:127.0.0.1:80 valentin@192.168.1.14
(binding local port 80 may require root in the VM; otherwise use -L 127.0.0.1:8080:127.0.0.1:80 and browse http://localhost:8080 — still localhost, so the webcam still works)
and turn on System Settings / general / sharing / Remote Login on the host


mettre à jours les APP_ALLOWED_HOSTS dans le .env
retirer les fichiers d'agents
retirer les commentaires ?

Pouvoir Switch avec et sans bonus ?

--logging----
Voici le récapitulatif des mécanismes, fichier par fichier, avec l'endroit exact où chacun est câblé :

1. app.log — redirection par le code applicatif

Table 1

Mécanisme
Où
define('APP_LOG_FILE', getenv('APP_LOG_FILE') ?: '/var/log/camagru/app.log')

src/bootstrap.php:30
app_log() : @file_put_contents(APP_LOG_FILE, $line, FILE_APPEND | LOCK_EX)

src/bootstrap.php
 — c'est la seule écriture de ce fichier
Routage des erreurs vers elle : set_exception_handler → app_log_throwable(), set_error_handler (convertit tout warning/notice en exception), register_shutdown_function (fatals : E_ERROR, E_PARSE...)

src/bootstrap.php
2. php_errors.log — redirection par le moteur PHP

Table 2

Mécanisme
Où
error_log = /var/log/camagru/php_errors.log + log_errors = On + display_errors = Off

docker/php/php.ini
C'est le filet de sécurité du moteur : il ne prend que ce que les handlers de bootstrap.php n'ont pas intercepté (erreur avant leur enregistrement, au tout début du chargement).

3. msmtp.log — redirection par la config msmtp générée

Table 3

Mécanisme
Où
cat > /etc/msmtprc <<EOF ... logfile ${LOG_DIR}/msmtp.log ... syslog off — la config est générée au démarrage depuis les variables .env

docker/php/entrypoint.sh
 (section 1)
chown -R www-data:www-data "$LOG_DIR" — sans ça, mail() (exécuté en www-data) ne pourrait pas écrire le log

docker/php/entrypoint.sh
4. nginx_error.log — redirection par réécriture de la conf nginx au démarrage

Table 4

Mécanisme
Où
command: qui fait sed -i 's|error_log /var/log/nginx/error.log notice;|error_log /var/log/camagru/nginx_error.log error;|' puis exec nginx
docker-compose.yml (service nginx) — le niveau passe de notice à error au passage
Les suppressions complémentaires (ce qui est dévié vers rien plutôt que vers un fichier — l'autre moitié du silence console) :

Table 5

Mécanisme
Où
Ce qu'il tue
sendmail_path = "/usr/bin/msmtp -t 2>/dev/null"

docker/php/php.ini
le stderr de msmtp (sinon il aboutit dans la console du conteneur)
access.log = /dev/null + log_level = error (sed dans le Dockerfile)

docker/php/camagru-fpm.conf
 + 
docker/php/Dockerfile
l'access log php-fpm et ses notices de démarrage
access_log off

docker/nginx/default.conf
l'access log nginx
NGINX_ENTRYPOINT_QUIET_LOGS=1
docker-compose.yml (env nginx)
la ligne d'info de l'entrypoint de l'image
log_min_messages=warning + log_checkpoints=off
docker-compose.yml (command db)
DEBUG/INFO/NOTICE/LOG runtime de Postgres — ses LOG de démarrage restent acceptés

----------




To review camagru : 

I want to check every thing you have produced in this run.
So please tell me in which order should i check your work. Give me each file to check from the first to the last one.

Phase 1 : 
Here is a sensible review order — contracts and plan first (so you know what to expect), then the infrastructure layer (bottom-up), then the database, then the application skeleton (entry point outward to views), then config and notes.

1.  PLAN.md                     (only the Phase 1 ticks + Notes section changed)
2.  .gitignore                  (expanded from just ".env")
3.  docker-compose.yml          (the whole stack: services, volumes, ports)
4.  docker/php/Dockerfile       (image build: gd, pdo_pgsql, msmtp, CA, fpm log_level)
5.  docker/php/php.ini          (display_errors off, session hardening, sendmail_path)
6.  docker/php/camagru-fpm.conf (pool-level: access log off, catch_workers_output)
7.  docker/php/entrypoint.sh    (msmtp config generation, DB setup, exec php-fpm)
8.  docker/nginx/default.conf   (front controller, static, /uploads no-PHP, headers)
9.  db/schema.sql               (idempotent full schema, section 7)
10. bin/setup-db.php            (wait for DB, detect missing tables, apply schema)
11. src/bootstrap.php           (autoloader, helpers require, error/exception handler, session)
12. src/helpers.php             (e() — the XSS escape helper)
13. src/Core/Env.php            (env var reader)
14. src/Core/Database.php       (PDO connection with safe settings)
15. src/Core/NotFoundException.php (thrown by Router, caught for 404)
16. src/Core/Request.php        (wraps method, path, query, post, files, headers)
17. src/Core/Response.php       (status, headers, body; redirect + json helpers)
18. src/Core/Router.php         (route table, {param} matching)
19. src/Core/View.php           (renderPartial, renderPage with layout, share)
20. src/Core/Session.php        (get/set/forget, flash, regenerate, destroy)
21. src/Core/Csrf.php           (per-session token, hash_equals verify)
22. src/Core/Validator.php      (email, username, password, required rules)
23. src/routes.php              (route table — only GET / for now)
24. src/Controllers/HomeController.php (renders the home page)
25. public/index.php           (front controller: bootstrap, share, match, dispatch)
26. src/Views/layout/app.php    (header, nav, flash, main, footer, favicon + css links)
27. src/Views/pages/home.php    (hero block)
28. src/Views/pages/404.php      (not-found page, full layout)
29. src/Views/pages/500.php     (minimal standalone 500, no layout)
30. public/assets/css/app.css   (mobile-first, flexbox-only, no gap/grid/var)
31. config/overlays.php         (id => filename whitelist for the 4 overlays)
32. NOTES.md                    (tools justified, silence levers, accepted Postgres lines)


Phase 2 : Here is the complete review list — 30 files I touched this run, ordered so each file only builds on things already reviewed. README.md also shows as modified, but that's your own DNS note, not mine.
Review order
A. Infrastructure fixes (phase-1 files fixed during phase 2)
 1.  docker/php/entrypoint.sh — chown of log/uploads dirs to the php-fpm user (fixes silent logging failure)
 2.  docker/php/php.ini — sendmail_path now discards msmtp stderr (console-leak fix)
B. Mail layer (bottom-up) 3. src/Services/Mail/Mailer.php — the interface everything else depends on 4. src/Services/Mail/MessageBuilder.php — header sanitation, RFC 2047 subject, MIME parts (check the CR/LF stripping) 5. src/Services/Mail/MsmtpMailer.php — mail() + msmtp driver 6. src/Services/Mail/MailerFactory.php — driver selection from MAIL_DRIVER 7. src/Services/Mail/AppMailer.php — sendVerification() / sendPasswordReset(), link building
C. Email templates 8. src/Views/emails/verification.php 9. src/Views/emails/verification_text.php 10. src/Views/emails/reset_password.php 11. src/Views/emails/reset_password_text.php
D. Core 12. src/Core/SiteUrl.php — request-host allowlist for email links (your option B) 13. src/Core/Auth.php — session-backed current user 14. src/Core/Session.php — modified: added restart() (the rest is phase 1)
E. Models (all SQL lives here) 15. src/Models/User.php 16. src/Models/PasswordReset.php — check the atomic single-use claim
F. Controllers 17. src/Controllers/AuthController.php — register, verify, login, logout, forgot/reset (the biggest file; the timing/dummy-hash and no-enumeration logic deserve the most attention) 18. src/Controllers/AccountController.php — profile, password, notifications, delete
G. Page views 19. src/Views/pages/register.php 20. src/Views/pages/login.php 21. src/Views/pages/forgot_password.php 22. src/Views/pages/reset_password.php 23. src/Views/pages/account.php
H. Wiring 24. src/routes.php — all 9 new routes 25. public/index.php — modified: currentUser now comes from Auth::user()
I. Styles 26. public/assets/css/app.css — appended: auth forms, account sections, danger zone
J. Docs 27. PLAN.md — ticks, notes, decisions (including account deletion) 28. NOTES.md — msmtp lever, accepted Firefox warning, APP_ALLOWED_HOSTS 29. COMPATIBILITY.md — entry 4 (Firefox password warning) 30. PROJECT_CONTEXT.md — the deletion feature added to section 4.2
Not touched this phase: src/bootstrap.php, db/schema.sql, docker-compose.yml, nginx config, Validator, Csrf, Router — so you can skip those. Review commands if useful: git diff HEAD -- <file> for the modified ones, cat for the new ones (all new files are marked A in git status).

Phase 3 : Here is the review order, following the flow of a request through the app — each file builds on the previous one, so this sequence reads like a story. Seven files are new, four were modified (use git diff <file> on the modified ones; the new ones are untracked).
  1.  src/bootstrap.php (modified) — the two new constants APP_ROOT and APP_UPLOAD_DIR; that is the only change, everything else is phase 1.
  2.  src/Services/ImageException.php (new, 6 lines) — the exception whose messages are safe to show to the user.
  3.  src/Services/ImageComposer.php (new) — the heart of the phase: upload validation (is_uploaded_file, error codes, 5 MB), finfo + getimagesize type and dimension checks, GD decode, re-encode through a truecolor canvas, whitelist-only overlay resolution, alpha compositing, random filename. The class-level comment block explains the security reasoning.
  4.  src/Models/Image.php (new) — all images-table SQL, plus removeFile() which the DB cascade cannot do.
  5.  src/Controllers/EditorController.php (new) — auth-only /editor page and the single capture endpoint with its two response modes (JSON for the XHR capture, flash+redirect for the no-JS form).
  6.  src/Controllers/ImageController.php (new) — ownership-checked delete, row + file removal.
  7.  src/Controllers/AccountController.php (modified) — the only change is in deleteAccount: it now collects the filenames and unlinks them after the row deletion.
  8.  src/routes.php (modified) — the three new routes at the bottom.
  9.  src/Views/pages/editor.php (new) — the page markup: main section (video, overlay radios, upload form, capture button), side section with delete forms, and the versioned script include.
 10.  public/assets/js/editor.js (new) — overlay-gated buttons, the getUserMedia wrapper, srcObject/mozSrcObject/createObjectURL attachment, the toDataURL → atob → Blob → FormData → XHR capture (not fetch — COMPATIBILITY entry 5), thumbnail injection, and the secure-origin message.
 11.  public/assets/css/app.css (modified) — only the "Editor" section appended before the media query; flexbox only, no unlisted features.
 12.  Docs: COMPATIBILITY.md (entries 1-3 and 5-6), NOTES.md (GD/fileinfo usage, APP_UPLOAD_DIR, the Postgres "Skipping initialization" line), PLAN.md (ticks, notes).
Not changed but verified against your notes: config/overlays.php and the images table in db/schema.sql already existed; nginx limits and the /uploads/ PHP-execution block were confirmed as-is.


Phase 4 :
  Here is the review order, following the request flow — routing, then the data layer, the controllers, the views, the mail, and finally the styling. What to check in each file is on the right.
   #
        File
                                                         What to verify
   1
        src/routes.php
                                                         The four new routes (GET /, GET /images/{id}, POST /images/{id}/like, POST /images/{id}/comments), HomeController import gone
   2
        src/Models/Image.php
                                                         The three added methods only: countAll(), page() (bound LIMIT/OFFSET with PDO::PARAM_INT), findWithAuthor() (join + like/comment sub-selects)
   3
        src/Models/Like.php
                                                         exists/add/remove/countByImage/toggle; the ON CONFLICT DO NOTHING race-safety comment
   4
        src/Models/Comment.php
                                                         create() and allByImage() (username join, oldest first)
   5
        src/Core/Validator.php
                                                         The added maxLength() rule (mbstring, one job)
   6
        src/Controllers/GalleryController.php
                                                         PER_PAGE = 6, normalizePage() (fallback to 1, clamp to last page), index()/show(), 404 on unknown id, date formatting
   7
        src/Controllers/LikeController.php
                                                         auth → CSRF → 404 → toggle → redirect order
   8
        src/Controllers/CommentController.php
                                                         MAX_BODY_LENGTH = 1000, same guard order, notifyAuthor() preference logic (all four branches), mail failure logged but never breaking the request
   9
        src/Services/Mail/AppMailer.php
                                                         The added sendCommentNotification() — template names, link building, subject
   10
        src/Views/emails/comment_notification.php
                                                         HTML body: every value escaped, nl2br only here
   11
        src/Views/emails/comment_notification_text.php
                                                         Plain-text twin of the same content
   12
        src/Views/pages/gallery.php
                                                         Empty state, grid, escaped author names, singular/plural counts, pagination links
   13
        src/Views/pages/image.php
                                                         h1, like form vs login link for visitors, e() on the comment body without nl2br (the CSS renders newlines), comment form only for logged-in users
   14
        public/assets/css/app.css
                                                         The removed .hero block; the new gallery/pagination/image-view/comments sections; no gap, no grid, no custom properties; the min-width: 640px block
   15
        Deleted
                                                         src/Controllers/HomeController.php and src/Views/pages/home.php are gone — nothing still references them (grep -r "HomeController|pages/home" src/ should return nothing)
   16
        PLAN.md
                                                         Phase 4 boxes and the Phase 4 notes only
  One deliberate design choice to be aware of while you read: file 8 does not re-fill the comment text after a validation error (the flash names the problem instead) — that is the only spot where I traded convenience for simplicity, so flag it if you want it changed.


Phase 9 : Here is the complete Phase 9 file list in review order — server foundation first, then views, then client code, then docs. This is every file I touched in this run, nothing else.
 #
      File
                                              What to look at
 1
      src/Views/partials/comment.php
                                              New: one comment <li>; escaping with e() lives here (page loop and AJAX response share it)
 2
      src/Views/partials/gallery_item.php
                                              New: one gallery card
 3
      src/Views/partials/gallery_items.php
                                              New: loops the cards (used by the gallery page and the JSON mode)
 4
      src/Models/Comment.php
                                              One added method: countByImage()
 5
      src/Controllers/LikeController.php
                                              XHR branch: {liked, count} on 200, JSON 401 (logged out) / 403 (no CSRF); plain path unchanged
 6
      src/Controllers/CommentController.php
                                              XHR branch: rendered partial + count on 200, JSON 422 (validation) / 403 / 401; notification logic untouched
 7
      src/Controllers/GalleryController.php
                                              XHR branch in index(); new show() extras: shareLinks() and ogMeta() (both server-built, escaped)
 8
      src/Views/layout/app.php
                                              One line added: optional $headMeta insertion in <head>
 9
      src/Views/pages/image.php
                                              data-image-id, ids for the JS hooks, share links block, comment partial loop, image.js?v=1 include
 10
      src/Views/pages/gallery.php
                                              data-page/data-total-pages on the grid, items via partial, gallery.js?v=1 include; nav unchanged
 11
      src/Views/pages/editor.php
                                              Preview canvas in .editor__preview, data-overlay-src on the overlay radios, ?v=3 bump
 12
      public/assets/js/image.js
                                              New: XHR like + comment (ES5, no fetch, inline status on error)
 13
      public/assets/js/gallery.js
                                              New: infinite scroll; nav hidden while loading, restored on failure
 14
      public/assets/js/editor.js
                                              Only the overlay-preview additions (canvas refs, selectOverlayPreview, startOverlayPreview, drawOverlayPreview, one extra listener, one call in the stream callback) — the six workarounds are untouched
 15
      public/assets/css/app.css
                                              "Bonuses (phase 9)" block: overlay canvas, share links, status line
 16
      PLAN.md
                                              Phase 9 section, current-step line, three Notes entries
A useful cross-check: git status --short should list exactly these (11 new/modified under src/ and public/, plus PLAN.md) and nothing else. If you want, I can walk through any of them with you — say the number and I'll show the diff.