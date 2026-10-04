# Camagru expliqué de A à Z

Ce document est un guide complet du projet Camagru, écrit pour être lu **dans l'ordre**,
en parallèle du code. Il suppose que tu connais presque rien de PHP, très peu de
JavaScript, et qu'il te faut des détails sur Docker, PostgreSQL et SQL. Chaque notion
est expliquée au moment où on la rencontre, avec des références précises aux fichiers
(`chemin/fichier.php`) pour que tu puisses lire le code en même temps.

Sommaire :

- **Partie 0** — Le projet en entier expliqué avant le code : vocabulaire, trajet d'une
  requête, mini-cours PHP et HTTP.
- **Phase 1** — Le squelette qui marche (« walking skeleton ») : Docker, nginx,
  PostgreSQL, le noyau PHP (`src/Core`).
- **Phase 2** — Les comptes : inscription, email de confirmation, connexion, mot de
  passe oublié, page compte, et toute la couche mail.
- **Phase 3** — L'éditeur : la webcam, l'upload, le traitement d'image côté serveur
  (GD), et le JavaScript de compatibilité.
- **Phase 4** — La galerie publique : pagination, likes, commentaires, emails de
  notification.
- **Phases 5 à 7** — Revue de sécurité, passage de compatibilité complet, validation
  finale (un seul commit « phase 5-6-7 » dans l'historique git).
- **Phase 8** — Option `SmtpMailer` : sautée par décision, expliquée en dix lignes.
- **Phase 9** — Les bonus : AJAX, défilement infini, aperçu d'overlay en direct,
  partage social.
- **Annexes** — Lancer le projet, variables d'environnement, arborescence annotée,
  glossaire.

Un point de méthode important : ce projet a été construit **phase par phase**, et
l'état actuel du dépôt est l'état final. Quelques fichiers cités dans les journaux de
bord historiques (par exemple `Mailer.php`, `MailerFactory.php`, `HomeController.php`,
`pages/home.php`) **n'existent plus aujourd'hui** : à chaque fois que c'est le cas, le
document le signale et explique pourquoi.

---

# Partie 0 — Comprendre l'ensemble avant d'ouvrir le code

## 0.1 Le projet en une page

Camagru est le projet web classique de l'école 42. C'est un petit réseau social
d'images :

1. Un visiteur **s'inscrit** (email + nom d'utilisateur + mot de passe), reçoit un
   **email de confirmation**, puis peut **se connecter**.
2. Un utilisateur connecté ouvre l'**éditeur** : il prend une photo avec **sa webcam**
   ou **uploade** une image, choisit un des 4 « overlays » (autocollants PNG avec
   transparence : chat, soleil, arbre, volcan), et le serveur **superpose l'overlay sur
   la photo** avec la bibliothèque GD de PHP.
3. Le résultat va dans la **galerie publique**, visible par tout le monde, où les
   utilisateurs connectés peuvent **liker** et **commenter** les images.
4. L'auteur d'une image reçoit un **email** quand quelqu'un la commente (sauf s'il a
   désactivé l'option dans sa page compte).

Les contraintes de l'école (section 1 de la spec, `PROJECT_CONTEXT.md`) sont drastiques
et expliquent beaucoup de choix du code :

- **PHP, bibliothèque standard uniquement** : pas de Composer (le gestionnaire de
  paquets PHP), pas de framework (pas de Laravel/Symfony), pas de moteur de template.
  Tout le MVC est écrit à la main.
- **JavaScript « vanilla »** : aucune bibliothèque JS (pas de jQuery, React, etc.), et
  en plus ici en style **ES5** (l'ancienne syntaxe JavaScript), parce que le projet
  doit marcher sur **Firefox 41** et **Chrome 46** (2015), deux navigateurs très
  anciens que l'utilisateur teste dans une machine virtuelle.
- **CSS écrit à la main** : aucun framework CSS, flexbox uniquement (pas de grid, pas
  de variables CSS).
- **Zéro sortie console** : ni erreurs, ni warnings, ni logs dans la console du
  navigateur ni dans la sortie des conteneurs Docker. C'est une exigence de notation,
  et elle explique des bidouillages très précis dans la configuration Docker.
- **Sécurité** : pas de mot de passe en clair, pas d'injection HTML/JS (XSS), pas
  d'injection SQL, pas d'upload malveillant, protection CSRF sur tous les formulaires.
- **Un seul conteneur à lancer** : après avoir créé `.env` à la main,
  `docker compose up --build` doit faire marcher tout le site, base de données comprise,
  depuis un clone frais — la base se crée toute seule si elle est vide.

## 0.2 Le vocabulaire indispensable

| Terme | Signification |
|---|---|
| **PHP** | Langage de programmation exécuté **côté serveur**. Le navigateur ne voit jamais le code PHP, seulement le HTML qu'il a produit. |
| **nginx** | Serveur web : c'est lui qui reçoit les requêtes HTTP. Il sert les fichiers statiques (CSS, JS, images) tout seul, et transmet le reste à PHP. |
| **php-fpm** | Le « PHP FastCGI Process Manager » : un programme qui fait tourner des processus PHP et attend que nginx lui envoie du travail. nginx et PHP ne sont pas dans le même conteneur ici, ils se parlent via le réseau Docker. |
| **PostgreSQL** | Le moteur de base de données (souvent abrégé « Postgres »). On y accède en SQL. |
| **SQL** | Le langage des bases de données relationnelles : `SELECT`, `INSERT`, `CREATE TABLE`... |
| **PDO** | La couche PHP standard pour parler à une base de données, indépendante du moteur (Postgres, MySQL...). |
| **Docker** | Outil qui empaquette un programme + ses dépendances dans un « conteneur » isolé. |
| **MVC** | Model-View-Controller : organisation en 3 couches. **Model** = les données (SQL), **View** = le HTML, **Controller** = la logique entre les deux. Ici : `src/Models`, `src/Views`, `src/Controllers`. |
| **Requête HTTP** | Ce que le navigateur envoie : une méthode (`GET` = lire une page, `POST` = envoyer des données), un chemin (`/login`), et éventuellement des données. |
| **Session** | Mécanisme qui permet au serveur de reconnaître un visiteur d'une page à l'autre (via un cookie) et donc de garder quelqu'un « connecté ». |
| **CSRF** | Cross-Site Request Forgery : attaque où un autre site fait soumettre un formulaire à ta place. Parade : un jeton secret caché dans chaque formulaire, vérifié côté serveur. |
| **XSS** | Cross-Site Scripting : injection de HTML/JS par un champ utilisateur (par exemple un commentaire contenant `<script>`). Parade : échapper toute sortie avec `e()`. |
| **Overlay** | Image PNG transparente superposée sur la photo de l'utilisateur. |

## 0.3 Le trajet complet d'une requête

Pour fixer les idées, voici ce qui se passe quand un navigateur demande
`http://localhost/images/42` (la page de détail d'une photo) :

```
Navigateur                     nginx                    php-fpm                  PostgreSQL
   |                              |                         |                        |
   |-- GET /images/42 ----------->|                         |                        |
   |    (cookie de session)       |                         |                        |
   |                              | fichier statique ?       |                        |
   |                              | /uploads/*, /assets/* -> servi direct par nginx    |
   |                              | sinon -> index.php       |                        |
   |                              |-- fastcgi (réseau Docker)--> public/index.php     |
   |                              |                         | bootstrap.php          |
   |                              |                         |   session, autoload    |
   |                              |                         | Auth::user() --------->| (SELECT)
   |                              |                         |   <--- ligne user     |
   |                              |                         | Router: /images/{id}   |
   |                              |                         |   -> GalleryController |
   |                              |                         | Image::findWithAuthor->| (SELECT)
   |                              |                         |   <--- ligne image    |
   |                              |                         | Comment::allByImage --->| (SELECT)
   |                              |                         |   <--- commentaires  |
   |                              |                         | View::renderPage(...)  |
   |                              |                         |   = HTML assemblé     |
   |                              |<-- HTML complet ---------|                        |
   |<-- 200 OK + HTML ------------|                         |                        |
   |   (le navigateur exécute     |                         |                        |
   |    image.js sur cette page)  |                         |                        |
```

À retenir : **un seul point d'entrée PHP** (`public/index.php`), un seul fichier que
nginx transmet à php-fpm. Tout le reste (`src/`, `db/`, `config/`, `.env`) est
physiquement inaccessible depuis le web, car nginx ne publie que le dossier `public/`.

## 0.4 Mini-cours PHP : juste ce qu'il faut pour lire le code

Si tu connais un autre langage (C, Python...), PHP te paraîtra familier. Voici le
sous-ensemble utilisé dans Camagru.

**Balises.** Le code PHP vit entre `<?php` et `?>`. Dans les vues, `<?= ... ?>` est le
raccourci de `<?php echo ... ?>` : il affiche la valeur. Exemple dans
`src/Views/layout/app.php` :

```php
<title><?= e($title ?? 'Camagru') ?></title>
```

`$title ?? 'Camagru'` veut dire « la variable `$title` si elle existe, sinon
`'Camagru'` ». La fonction `e()` (voir plus bas) échappe le HTML.

**Variables.** Elles commencent par `$` : `$user`, `$images`. Pas de déclaration de
type obligatoire, mais ce projet utilise `declare(strict_types=1);` en haut de chaque
fichier : les fonctions refusent alors les types trompeurs (par exemple passer la
chaîne `"5"` à une fonction qui attend un `int` devient une erreur au lieu d'une
conversion silencieuse). C'est plus rigoureux et plus sûr.

**Fonctions.**

```php
function app_log(string $message): void { ... }
```

`string $message` = paramètre typé, `: void` = ne renvoie rien.

**Tableaux.** Deux sortes, avec la même syntaxe :

```php
$user = ['username' => 'alice', 'id' => 7];  // tableau associatif (comme un dict)
$user['username'];                              // 'alice'
$names = ['alice', 'bob'];                     // liste numérotée
foreach ($names as $name) { ... }
```

PDO renvoie toujours des tableaux associatifs qui imitent une ligne de table SQL :
`$user['password_hash']` correspond à la colonne `password_hash` de la table `users`.

**Classes, objets, statique.** Le projet utilise presque exclusivement des **classes
statiques** : on n'instancie presque rien (sauf `Validator`, `Request` et les
contrôleurs). La différence :

```php
$user = new Validator();   // objet ; les méthodes s'appellent avec ->
$user->email('email', $v);

Validator::something();    // méthode statique (de classe) ; s'appelle avec ::
```

Les modèles (`User::findById(3)`), le routeur, la vue, la session... sont statiques :
ce sont des « rangements à fonctions » groupées par thème, pas des objets à état.

**Espaces de noms (namespaces).** En haut de chaque classe :

```php
namespace App\Core;     // cette classe vit dans src/Core/
```

Règle du projet : le préfixe `App\` correspond au dossier `src/`, et la suite du nom
correspond au sous-dossier (`App\Controllers\AuthController` =
`src/Controllers/AuthController.php`). Le lien entre le nom et le fichier est fait par
l'« autoloader » (voir 1.10).

**Superglobales.** Variables spéciales remplies par PHP à chaque requête :

| Variable | Contenu |
|---|---|
| `$_GET` | Les paramètres d'URL (`/verify?token=abc` → `$_GET['token']`) |
| `$_POST` | Les champs d'un formulaire POST |
| `$_FILES` | Les fichiers uploadés |
| `$_SESSION` | Les données de la session du visiteur |
| `$_SERVER` | Les en-têtes HTTP et infos du serveur (`$_SERVER['REQUEST_URI']`...) |

**Conditions/affichage dans les vues.** Les templates alternent HTML et PHP :

```php
<?php if ($currentUser !== null): ?>
    ... HTML si connecté ...
<?php else: ?>
    ... HTML sinon ...
<?php endif; ?>
```

**Les fonctions de hachage de mots de passe** — la seule chose jamais stockée en clair :

```php
password_hash($password, PASSWORD_DEFAULT);  // produit "openssl hash" illisible : $2y$10$...
password_verify($password, $hash);            // true si le mot de passe correspond au hash
```

`PASSWORD_DEFAULT` utilise bcrypt. Le hachage est **unidirectionnel** : on ne peut pas
retrouver le mot de passe, seulement vérifier une candidature.

**L'échappement HTML, la fonction `e()`.** Dans `src/helpers.php` :

```php
function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

`htmlspecialchars` transforme `<`, `>`, `"`, `'`, `&` en entités HTML (`&lt;` etc.). Un
commentaire contenant `<script>alert(1)</script>` sera donc affiché comme du **texte**
« `<script>alert(1)</script>` » au lieu d'être **exécuté**. C'est la défense XSS unique
du projet, appliquée à **chaque** valeur utilisateur rendue dans une vue : noms
d'utilisateur, commentaires, messages flash, emails.

## 0.5 Mini-cours HTTP et application web

- Une requête = **méthode + chemin + en-têtes + corps éventuel**. `GET /login` lit la
  page ; `POST /login` avec `username=...&password=...` dans le corps soumet le
  formulaire.
- Une réponse = **code de statut + en-têtes + corps**. `200` OK, `302` redirection
  (l'en-tête `Location: /` dit où aller), `404` introuvable, `401/403/422` interdit/
  non authentifié/erreur de validation, `500` erreur serveur.
- Le protocole est **sans état** : chaque requête est indépendante. La session (cookie
  `camagru_sid` + données stockées côté serveur) recrée artificiellement un état.
- **Redirect-After-Post** : après un POST réussi (par exemple créer un compte), on
  redirige vers une page GET. Un rafraîchissement ne repart pas le formulaire.

---

# Phase 1 — Le squelette qui marche (« walking skeleton »)

**Objectif de la phase.** Livrer la fondation complète mais quasi vide : la stack
Docker (nginx + php + postgres), le schéma SQL, le noyau PHP (routeur, vue, session,
CSRF...), une page d'accueil, une page 404, une page 500 — et zéro ligne dans les
consoles. Tout ce que les phases suivantes feront, c'est **ajouter des routes, des
contrôleurs, des modèles et des vues** dans la structure posée ici.

Ordre de lecture recommandé (celui du journal de bord de la phase) : contrats
d'abord (`PLAN.md`, `.gitignore`), puis infrastructure (`docker-compose.yml`,
`docker/`), puis base de données (`db/schema.sql`, `bin/setup-db.php`), puis le
squelette applicatif (`src/bootstrap.php` vers les vues), puis config et docs.

## 1.1 `PLAN.md` et `.gitignore`

- `PLAN.md` est le tableau de bord du projet : les phases dans l'ordre, des cases à
  cocher (cochées seulement après vérification), et une section « Notes » en bas qui
  trace chaque décision et chaque incident, dans l'ordre chronologique. C'est la
  mémoire du développement.
- `.gitignore` liste ce que git ignore. Trois choses importantes :
  - `.env` : le fichier des mots de passe et secrets, **jamais commité** ;
  - `uploads/` : les images générées vivent dans un volume Docker, pas dans le dépôt ;
  - `*.log`, `logs/` : les logs vont dans un volume, jamais dans le dépôt.

## 1.2 Docker : les notions, puis le fichier

**Image vs conteneur.** Une **image** est un modèle figé (une « recette » construite
par un `Dockerfile`) ; un **conteneur** est un processus vivant lancé à partir d'une
image. Analogie : l'image est le programme, le conteneur est le processus.

**Docker Compose** (fichier `docker-compose.yml`) décrit une **stack** de plusieurs
conteneurs qui partagent un réseau privé : ici `db`, `php`, `nginx`. Commandes : `docker
compose up --build` (construit les images si besoin et démarre tout), `docker compose
logs` (voir la sortie des conteneurs), `docker compose down` (arrêter).

**Volumes nommés.** Un volume est un disque géré par Docker qui survit aux
conteneurs. Trois ici :

- `db_data` : les données Postgres — si tu détruis le conteneur `db`, les données
  restent ;
- `uploads` : les images compositées, écrit par `php`, lu par `nginx` ;
- `logs` : tous les fichiers de log (app, php, nginx, msmtp).

**Réseau.** Compose crée automatiquement un réseau privé où chaque service est
joignable **par son nom** : dans la config PHP, l'hôte de la base s'écrit `db` (le nom
du service), pas `localhost` — chaque conteneur a son propre `localhost`. De même nginx
parle à php-fpm via `php:9000`.

**`ports` vs `expose`.** `ports: "80:80"` **publie** le port sur la machine hôte (le
navigateur de l'hôte peut atteindre le site). `expose: "5432"` rend le port joignable
**seulement dans le réseau interne Docker** : la base n'est pas atteignable depuis la
machine hôte, ce qui réduit la surface d'attaque (le port de la DB n'est pas publié,
exigence de la spec).

**Healthcheck et `depends_on`.** Le `db` a un `healthcheck` (`pg_isready`) : Docker
sonde toutes les 3 s si Postgres accepte les connexions. Le service `php` déclare
`depends_on: db: condition: service_healthy` : il ne démarre **que quand la base est
prête**. Sans cela, PHP démarrerait avant Postgres et le script de setup échouerait.

### `docker-compose.yml` lu de près

```yaml
services:
  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: ${POSTGRES_DB}
      ...
```

- `image: postgres:16-alpine` : image officielle Postgres 16, variante Alpine (très
  petite). Les variables `${POSTGRES_DB}` etc. sont lues dans `.env` au moment de la
  commande.
- `command: ["postgres", "-c", "log_min_messages=warning", "-c", "log_checkpoints=off"]` :
  on remplace la commande par défaut pour passer des options de log directement au
  serveur Postgres. `log_min_messages=warning` supprime les messages DEBUG/INFO/NOTICE.
  `log_checkpoints=off` (ajouté en phase 5, voir la partie « Phases 5 à 7 ») supprime
  les lignes `LOG: checkpoint starting/complete` que Postgres 15+ écrit toutes les
  5 minutes : c'était de la sortie console **en cours d'exécution**, interdite.
- `php` : `build:` construit l'image depuis `docker/php/Dockerfile` ; `env_file: .env`
  injecte toutes les variables du `.env` dans le conteneur (c'est comme ça que le code
  PHP les lit avec `getenv`) ; `depends_on` avec condition de santé ; les montages :
  le code du projet est monté dans le conteneur (`.:/var/www/camagru`), le volume
  `uploads` est monté sur `/var/www/camagru/uploads` et le volume `logs` sur
  `/var/log/camagru`.
- `nginx` : `ports: "80:80"` — le **seul** service qui publie un port (le site est
  servi sur `http://localhost`). Sa `command:` modifie au démarrage le fichier
  `/etc/nginx/nginx.conf` de l'image (`sed`) pour rediriger l'`error_log` vers un
  fichier, au niveau `error` seulement — sinon l'image nginx imprime des notices
  « start worker process » dans la console. `NGINX_ENTRYPOINT_QUIET_LOGS: "1"` taire
  la ligne d'information de l'entrypoint de l'image. Le code du projet y est monté
  **en lecture seule** (`:ro`) et le volume `uploads` aussi (`:ro`) : nginx peut servir
  les images, il ne peut pas les modifier.

## 1.3 `docker/php/Dockerfile` — construire l'image PHP

Un Dockerfile est une suite d'instructions qui construisent une image. Le nôtre part
de l'image officielle `php:8.3-fpm-alpine` et ajoute ce qu'il faut :

1. **`apk add`** (le gestionnaire de paquets d'Alpine) installe les bibliothèques
   runtime : `freetype`, `libjpeg-turbo`, `libpng` (pour GD, le traitement d'image),
   `postgresql-libs` (pour pdo_pgsql), **`msmtp`** (le relais d'emails, voir phase 2)
   et `ca-certificates` (certificats TLS pour que msmtp puisse chiffrer vers le serveur
   SMTP).
2. Un deuxième `apk add --virtual .build-deps` installe les paquets de **compilation**
   (`*-dev`...), puis `docker-php-ext-install gd pdo_pgsql` **compile et active** les
   extensions PHP `gd` (images) et `pdo_pgsql` (base de données), puis `apk del
   .build-deps` **désinstalle** les outils de compilation : l'image finale reste
   petite. (Les scripts `docker-php-ext-*` sont fournis par l'image PHP officielle.)
3. `RUN php -m | grep -E '^(gd|pdo_pgsql|fileinfo|mbstring)$'` : la construction
   **échoue** si une extension requise manquait — un filet de sécurité.
4. `sed -i 's|^;log_level = notice|log_level = error|' /usr/local/etc/php-fpm.conf` :
   baisse le niveau de log de php-fpm pour taire les notices de démarrage (« ready to
   handle connections »).
5. `COPY` de `camagru-fpm.conf`, `php.ini` et `entrypoint.sh` dans l'image.
6. `ENTRYPOINT`/`CMD` : au démarrage du conteneur, c'est `entrypoint.sh` qui prend la
   main (et se termine par `exec php-fpm`, voir 1.6).

## 1.4 `docker/php/php.ini` — la configuration d'exécution

Le `php.ini` règle le comportement de PHP. Lignes notables :

- `display_errors = Off` + `log_errors = On` + `error_log = /var/log/camagru/...` :
  **jamais** d'erreur affichée à l'utilisateur (fuite d'information), tout va dans un
  fichier du volume `logs`.
- `upload_max_filesize = 6M` / `post_max_size = 7M` : limites d'upload de PHP, alignées
  avec `client_max_body_size 7m` de nginx (voir 1.7). L'application re-vérifie 5 Mo en
  code ; les limites infrastructure sont volontairement un cran au-dessus pour que ce
  soit **notre** code qui rejette proprement un fichier trop gros avec un vrai message.
- Session : `session.use_strict_mode = 1` (refuse un identifiant de session inventé par
  le client), `use_only_cookies = 1` (pas d'identifiant dans les URL),
  `cookie_httponly = 1` (le cookie est invisible en JavaScript), `samesite = "Lax"`
  (le cookie n'est pas envoyé depuis un autre site — défense CSRF supplémentaire).
- `sendmail_path = "/usr/bin/msmtp -t 2>/dev/null"` : quand le code PHP appelle
  `mail()`, c'est en réalité `msmtp` qui est invoqué (voir phase 2). Le `2>/dev/null`
  jette la sortie d'erreur de msmtp pour qu'un échec d'envoi **n'écrive jamais** dans la
  console du conteneur (il est toujours dans le fichier de log msmtp).
- `expose_php = Off` : ne pas annoncer la version de PHP dans les en-têtes.

## 1.5 `docker/php/camagru-fpm.conf` — le réglage du « pool »

php-fpm exécute le code dans des « workers » regroupés en pools. Deux directives :

- `access.log = /dev/null` : le journal d'accès de php-fpm (une ligne **par requête**)
  est désactivé — sinon chaque clic sur le site écrirait dans la console. C'est la
  ligne la plus importante pour la politique « zéro console ».
- `catch_workers_output = yes` : ce que les workers écriraient sur stdout/stderr est
  routé vers le log d'erreur du master (fichier), pas vers la console.

## 1.6 `docker/php/entrypoint.sh` — le script de démarrage

Un « entrypoint » est le premier programme lancé dans le conteneur. Le nôtre fait
trois choses, **sans rien imprimer** :

1. **Préparer les permissions.** Les workers php-fpm tournent comme l'utilisateur
   `www-data`. Or le conteneur démarre en root. Sans le `chown -R www-data:www-data
   /var/log/camagru` et le `chown` du dossier uploads, chaque écriture de log (et
   chaque envoi d'email par msmtp, lancé par www-data) échouerait silencieusement avec
   « Permission denied ». C'est un bug réel de la phase 2, corrigé ici (les journaux de
   bord de phase 2 le mentionnent).
2. **Générer `/etc/msmtprc`** (la configuration de msmtp) **à partir des variables
   d'environnement** : hôte, port, utilisateur, mot de passe SMTP, expéditeur. Le
   `case "${SMTP_SECURE...}"` choisit `tls_starttls on` (port 587) ou `off` (port 465,
   TLS dès le début). Le fichier est protégé (`chmod 600`, propriétaire www-data) car
   il contient le mot de passe SMTP. Générer la config au démarrage évite de committer
   un fichier avec des secrets : seules des **variables** sont dans le dépôt.
3. **`php /var/www/camagru/bin/setup-db.php`** : attendre la base et créer les tables
   manquantes (voir 1.9). Puis **`exec php-fpm`** : `exec` remplace le shell courant
   par php-fpm, qui devient le processus principal du conteneur (il reçoit les signaux
   d'arrêt proprement).

## 1.7 `docker/nginx/default.conf` — la configuration du serveur web

Un fichier de « server » nginx. Points un par un :

- `root /var/www/camagru/public;` : **seul** le dossier `public/` est exposé au web.
  Même si un attaquant devine le chemin de `src/` ou de `.env`, nginx ne peut pas les
  servir : ils sont hors de `root`.
- `server_tokens off;` : ne pas afficher la version de nginx dans les erreurs.
- `access_log off;` : pas de log d'accès (politique console).
- `client_max_body_size 7m;` : taille max des requêtes, alignée avec PHP.
- **Les en-têtes de sécurité**, ajoutés à **chaque** réponse (`always`) :
  - `X-Content-Type-Options: nosniff` — le navigateur ne devine pas le type MIME
    d'un fichier (défense contre un fichier malveillant servi comme autre chose) ;
  - `X-Frame-Options: DENY` — la page ne peut pas être affichée dans un cadre sur un
    autre site (défense clickjacking) ;
  - `Referrer-Policy: same-origin` — l'URL complète n'est pas envoyée aux autres sites ;
  - `Content-Security-Policy` — restrictions sur ce que la page peut charger :
    scripts et styles uniquement depuis nous-mêmes, images aussi depuis `blob:` et
    `data:` (nécessaire pour l'aperçu webcam), media depuis `blob:`.
- **`location /uploads/`** : les images uploadées sont servies par nginx directement
  depuis le volume (`alias /var/www/camagru/uploads/`), **sans jamais passer par PHP**.
  Le bloc imbriqué `location ~ \.php$ { return 403; }` interdit toute exécution PHP
  dans ce dossier : même si un attaquant réussissait à y déposer un fichier
  `malware.php`, nginx répondrait 403 au lieu de l'exécuter.
- **`location /assets/`** : fichiers statiques avec `expires 1h` (cache navigateur
  d'une heure — d'où le versionnage `editor.js?v=4`, voir phase 3).
- **`location /`** : `try_files $uri /index.php?$query_string;` — le cœur du
  « front controller pattern » : si le chemin demandé existe comme fichier, nginx le
  sert ; sinon il transmet à `index.php` **avec les paramètres d'URL**. C'est ainsi
  que `/images/42` arrive à PHP.
- **`location = /index.php`** : transmet à `php:9000` via FastCGI (le protocole entre
  nginx et php-fpm) avec `fastcgi_param SCRIPT_FILENAME` qui indique quel fichier
  exécuter.
- **`location ~ \.php$ { return 404; }`** (après) : tout **autre** fichier `.php` que
  `index.php` est introuvable. Un attaquant ne peut pas appeler directement
  `src/Models/User.php` ni `bin/setup-db.php` par URL.

## 1.8 PostgreSQL et le schéma SQL (`db/schema.sql`)

### Ce qu'il faut savoir sur PostgreSQL

PostgreSQL est un serveur de base de données relationnelle : il stocke des **tables**
de lignes et de colonnes, et on l'interroge en **SQL**. Ici :

- Postgres tourne dans son propre conteneur ; les autres services s'y connectent par
  le réseau interne Docker (`DB_HOST=db`).
- Le volume `db_data` conserve les données entre les redémarrages.
- Au **premier** démarrage, l'image officielle crée la base et l'utilisateur indiqués
  par `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` (depuis `.env`). Ce n'est
  qu'après qu'elle accepte les connexions — d'où le healthcheck.
- **Une base ne crée pas ses tables toute seule** : c'est le rôle de
  `bin/setup-db.php` (1.9).

### Lire `db/schema.sql` ligne par ligne

Le fichier crée 5 tables. Tout est `IF NOT EXISTS` : le schéma est **idempotent** —
le ré-exécuter ne casse rien et ne détruit rien. C'est indispensable puisqu'il peut
être appliqué à chaque démarrage.

```sql
CREATE TABLE IF NOT EXISTS users (
    id                    SERIAL PRIMARY KEY,
    username              TEXT NOT NULL,
    email                 TEXT NOT NULL,
    password_hash         TEXT NOT NULL,
    is_verified           BOOLEAN NOT NULL DEFAULT false,
    verification_token_hash TEXT,
    notify_on_comment     BOOLEAN NOT NULL DEFAULT true,
    notify_on_own_comment BOOLEAN NOT NULL DEFAULT false,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

Vocabulaire :

- `SERIAL` : entier qui s'incrémente tout seul (1, 2, 3...) — l'équivalent
  d'AUTO_INCREMENT. `PRIMARY KEY` : identifiant unique de la ligne.
- `TEXT NOT NULL` : chaîne obligatoire ; `BOOLEAN NOT NULL DEFAULT false` :
  vrai/faux obligatoire, faux si non précisé.
- `TIMESTAMPTZ` : horodatage **avec fuseau horaire** ; `DEFAULT now()` : rempli par
  PostgreSQL lui-même à l'insertion. Faire dater par la base évite les écarts d'horloge
  entre PHP et Postgres.
- `is_verified` : le compte peut se connecter seulement si vrai (confirmation email).
- `verification_token_hash` : le **hash** du jeton de confirmation (jamais le jeton
  lui-même — même logique que les mots de passe : si la base fuit, les liens ne sont
  pas utilisables).
- `notify_on_comment` / `notify_on_own_comment` : les deux préférences d'email de la
  phase 4.

Les deux index qui suivent sont un point de sécurité important :

```sql
CREATE UNIQUE INDEX IF NOT EXISTS users_username_lower_uniq ON users (lower(username));
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_uniq    ON users (lower(email));
```

Un **index unique** interdit deux lignes avec la même valeur : la base elle-même
refuse un doublon. Le calcul sur `lower(...)` rend l'unicité **insensible à la
casse** (« Alice » et « alice » sont en conflit) — exigence de la spec. Note que ce
sont des **index** et pas une contrainte `UNIQUE` de table : fonctionnellement
équivalent, et ça marche aussi comme index de recherche.

```sql
CREATE TABLE IF NOT EXISTS password_resets (
    id         SERIAL PRIMARY KEY,
    user_id    INT REFERENCES users ON DELETE CASCADE,
    token_hash TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at    TIMESTAMPTZ
);
```

- `user_id INT REFERENCES users` : **clé étrangère**. La colonne doit contenir un `id`
  qui existe dans `users`. La base garantit qu'on ne peut pas créer un reset pour un
  utilisateur inexistant.
- `ON DELETE CASCADE` : si l'utilisateur est supprimé, **ses lignes ici sont
  supprimées automatiquement**. Ce mécanisme fait tout le travail de « supprimer mon
  compte » (phase 2) et « supprimer une image » (phase 3).
- `expires_at` : date limite du lien (1 h) ; `used_at` : `NULL` = pas encore utilisé,
  une date = consommé. **Un seul usage** est garanti par le code (`PasswordReset::claim`).

```sql
CREATE TABLE IF NOT EXISTS images (
    id         SERIAL PRIMARY KEY,
    user_id    INT REFERENCES users ON DELETE CASCADE,
    filename   TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS images_created_at_idx ON images (created_at DESC);
```

Le fichier stocké est représenté par son **nom** seulement (généré aléatoirement,
voir phase 3) ; le fichier lui-même vit dans le volume `uploads`. L'index sur
`created_at DESC` sert la galerie, toujours triée par date décroissante — sans index,
Postgres devrait retrier toutes les lignes à chaque page.

```sql
CREATE TABLE IF NOT EXISTS likes (
    user_id  INT REFERENCES users ON DELETE CASCADE,
    image_id INT REFERENCES images ON DELETE CASCADE,
    PRIMARY KEY (user_id, image_id)
);
```

**Clé primaire composite** : l'unicité porte sur le **couple**. Un utilisateur ne peut
pas liker deux fois la même image (la base refuse le doublon), mais deux utilisateurs
peuvent liker la même image. C'est l'exemple type de table de relation
« plusieurs-à-plusieurs ».

```sql
CREATE TABLE IF NOT EXISTS comments (
    id         SERIAL PRIMARY KEY,
    image_id  INT REFERENCES images ON DELETE CASCADE,
    user_id   INT REFERENCES users ON DELETE CASCADE,
    body      TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

Le `body` est stocké **brut** (tel qu'écrit, y compris `<script>`) : l'échappement est
une affaire d'**affichage** (vue), pas de stockage. C'est le bon réflexe : stocker
propre, échapper en sortie.

En résumé, la cascade `ON DELETE CASCADE` dessine cette chaîne :
supprimer un **user** → ses **images**, **likes**, **comments**, **password_resets**
disparaissent ; supprimer une **image** → ses **likes** et **comments** disparaissent.
Mais attention : une cascade SQL ne touche **jamais le système de fichiers** — les
fichiers sur le volume `uploads` doivent être supprimés par le code PHP (phase 3).

## 1.9 `bin/setup-db.php` — la base qui se crée toute seule

Exigence de la spec : « clone frais + `.env` + `docker compose up --build` = site qui
marche », sans étape manuelle de SQL. Le script, lancé par `entrypoint.sh` à chaque
démarrage du conteneur php :

1. **Attendre la base** (`waitForDatabase`) : essaie `Database::connection()` jusqu'à
   30 fois, 2 s d'intervalle ; si ça échoue, log fichier + `exit(1)` (le conteneur
   s'arrête plutôt que de servir un site cassé).
2. **Vérifier les tables** (`missingTables`) : demande à PostgreSQL quelles tables
   existent via sa table catalogue `pg_tables` :

   ```php
   $stmt = $pdo->prepare(
       "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename IN (...)"
   );
   ```

   `pg_tables` est une table spéciale de Postgres qui **décrit** la base. Note : les 5
   noms de tables sont passés en **paramètres liés** (`?`), pas concaténés dans la
   chaîne — même règle anti-injection partout.
3. **Si rien ne manque : ne rien faire, ne rien imprimer.** (Cas : base déjà
   initialisée.)
4. **Sinon : appliquer `db/schema.sql`** via `$pdo->exec($schema)`. Comme le schéma
   est idempotent, un état partiellement créé se répare aussi. Le script ne **drop ni
   ne troncate jamais rien** : une base existante est laissée intacte, données
   comprises.
5. Réussite silencieuse ; échec → log + code de sortie non nul.

À la première lecture, retiens le principe : *« dis-moi ce qui manque, je le crée ;
je ne touche jamais à ce qui existe. »*

## 1.10 `src/bootstrap.php` — ce qui s'exécute avant tout

Ce fichier est inclus par `public/index.php` **à chaque requête**, et par
`bin/setup-db.php` en CLI. Il fait quatre choses.

**1. L'autoloader.** En PHP, pour utiliser une classe il faut d'abord charger son
fichier (`require`). L'autoloader évite d'écrire des centaines de `require` :

```php
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) { require $file; }
});
```

Quand le code utilise `App\Models\User` pour la première fois, PHP appelle cette
fonction avec le nom de la classe ; elle en déduit le chemin
`src/Models/User.php` et le charge. C'est le mécanisme standard (PSR-4) que Composer
génère normalement — ici écrit à la main, puisque Composer est interdit. Les
`use App\Core\Router;` en tête de fichier ne chargent rien : ils **aliasent** juste les
noms pour écrire `Router` au lieu de `App\Core\Router`.

**2. Les constantes.**

- `APP_ROOT` : le dossier du projet — pour atteindre `config/overlays.php` depuis
  n'importe où.
- `APP_LOG_FILE` : destination du log applicatif (`/var/log/camagru/app.log`, le
  volume) — surchargeable par variable d'environnement sans toucher au `.env`.
- `APP_UPLOAD_DIR` : dossier des images générées (le volume `uploads`).

**3. La journalisation** — deux fonctions globales :

- `app_log($message)` : écrit une ligne datée dans le fichier, avec `@` (opérateur de
  silence) pour qu'un échec de log **ne fasse jamais échouer** la requête ;
- `app_log_throwable($e)` : formatte une exception en une ligne (classe, message,
  fichier, ligne) — sans stack trace côté utilisateur.

**4. Les gestionnaires d'erreurs.** Trois inscriptions :

- `set_exception_handler` : toute exception non attrapée → log fichier + page 500
  générique (« Something went wrong ») **sans aucun détail interne**. En CLI : sortie
  non nulle.
- `set_error_handler` : convertit **les warnings/notices PHP en exceptions**. Grâce à
  lui, un warning (par exemple une variable indéfinie) n'est pas ignoré : il remonte
  comme une erreur et finit loggé. C'est très exigeant, et c'est voulu : la politique
  « zéro sortie console » impose un code sans warning.
- `register_shutdown_function` : attrape les erreurs **fatales** (mémoire épuisée,
  parse error) que le gestionnaire précédent ne peut pas voir, les logge, et force le
  code 500.

**5. La session** (requêtes web seulement, pas en CLI) : paramètres de cookie sécurisés
(`httponly`, `samesite Lax` ; `secure` est off parce que le site est en HTTP sur
localhost), nom du cookie `camagru_sid`, puis `session_start()`. Si le navigateur
présente un cookie valide, `$_SESSION` contient les données sauvegardées lors des
requêtes précédentes ; sinon une session neuve est créée.

## 1.11 `src/helpers.php` — `e()` et `e_attr()`

Deux fonctions globales, chargées par bootstrap. `e()` est **la** défense XSS du
projet (détaillée en 0.4) ; `e_attr()` est identique mais porte un nom distinct pour
signaler, dans les templates, qu'une valeur atterrit dans un attribut HTML
(`src="/uploads/<?= e_attr($image['filename']) ?>"`). Règle de lecture du projet :
toute valeur utilisateur dans une vue **doit** passer par `e()` ou `e_attr()`.

## 1.12 Les classes de `src/Core` — le noyau fait maison

C'est un micro-framework écrit à la main. Chaque classe a **un seul travail**.

### `Env.php` — lire les variables d'environnement

Trois méthodes statiques : `get` (avec défaut), `require` (échec si absente — pour
`DB_HOST`, `APP_URL`...), `bool` (« 1 »/« true »/« on » → vrai). Les variables
viennent du `.env` injecté dans le conteneur par `env_file`. Aucune valeur n'est
jamais codée en dur : le code ne connaît que des **noms** de variables.

### `Database.php` — la connexion PDO

```php
self::$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
```

- Le **DSN** (`pgsql:host=db;port=5432;dbname=camagru`) dit à PDO de parler Postgres.
- `ERRMODE_EXCEPTION` : une erreur SQL lève une exception au lieu de continuer
  silencieusement.
- `EMULATE_PREPARES = false` : **désactive l'émulation** des requêtes préparées. PDO
  par défaut « simule » les paramètres en réécrivant la requête en PHP ; ici les
  vraies requêtes préparées de Postgres sont utilisées. C'est plus sûr (le serveur
  connaît les types) et c'est ce qui cause un détail à connaître : avec les « vraies »
  préparations, PHP doit envoyer des **types** corrects — d'où les
  `PDO::PARAM_INT`/`PDO::PARAM_BOOL` explicites qu'on verra plus loin.
- `FETCH_ASSOC` : les lignes reviennent en tableaux associatifs (`$row['username']`).
- La connexion est **statique et partagée** : une seule par requête, créée au premier
  appel (pattern « singleton »).
- En cas d'échec : log réel dans le fichier, mais l'exception levée dit seulement
  « Database is unavailable. » — aucun détail (ni mot de passe, ni hôte) ne remonte.

### `NotFoundException.php`

Une classe de 6 lignes : une sous-classe de `RuntimeException` sans rien ajouter. Le
routeur la lance quand rien ne correspond ; le front controller l'attrape et fabrique
le 404. Elle existe pour qu'on puisse attraper **ce cas précis** sans attraper toutes
les exceptions.

### `Request.php` — la requête, sans toucher les superglobales

Encapsule tout ce qui vient du client. Les contrôleurs reçoivent cet objet et ne
lisent **jamais** `$_GET`/`$_POST` directement (bonne pratique : le code devient
testable et l'accès est centralisé). Méthodes :

- `fromGlobals()` : construit la requête à partir de l'environnement. Détail utile :
  `HEAD` est traité comme `GET`, et le chemin est extrait de l'URI **sans** la query
  string (`parse_url($uri, PHP_URL_PATH)`) puis normalisé.
- `query($key)` / `post($key)` : lire un paramètre GET/POST avec défaut ;
- `file($key)` : une entrée de `$_FILES` ;
- `header($name)` : lit un en-tête HTTP. nginx/fastcgi transforme les en-têtes en
  variables `HTTP_X_REQUESTED_WITH` (d'où la recherche `HTTP_` + nom en majuscules,
  tirets en underscores) ;
- `isPost()`.

### `Response.php` — la réponse

Trois propriétés (statut, en-têtes, corps) et trois constructeurs statiques : `make`
(HTML simple), `redirect` (302 + en-tête `Location`), `json` (corps encodé en JSON +
`Content-Type: application/json` — utilisé par l'éditeur et les bonus). `send()`
émet le vrai statut (`http_response_code`), les en-têtes, puis le corps. Les
contrôleurs **construisent** des réponses et ne les envoient jamais eux-mêmes ; seul
le front controller appelle `send()`. Un seul endroit écrit sur la sortie : facile à
raisonner.

### `Router.php` — la table des routes

Deux méthodes :

- `add($method, $pattern, $controller, $action)` enregistre une route. Le motif est
  **compilé en expression régulière** : `/images/{id}` devient
  `#^images/(?<id>[^/]+)$#` (slashes racine compris). Chaque `{param}` devient un
  groupe nommé qui capture **un segment de chemin** (tout sauf `/`).
- `match($method, $path)` parcourt la table dans l'ordre d'enregistrement ; même
  méthode + motif qui colle → renvoie le handler (`[ClasseContrôleur, 'méthode']`) et
  les paramètres capturés (`['id' => '42']`). Sinon : `NotFoundException`.

Exemple concret : `GET /images/42` matche la route
`$router->add('GET', '/images/{id}', GalleryController::class, 'show')` et le
contrôleur recevra `$params['id'] === '42'` (une **chaîne** ; d'où les `(int)`
partout dans les contrôleurs).

### `View.php` — le moteur de template minimal

C'est le remplacement fait main d'un moteur comme Twig :

- `share($data)` : des variables partagées avec **toutes** les vues (le front
  controller y met `currentUser` et `csrfToken`).
- `renderPartial($template, $data)` : inclut le fichier `src/Views/$template` avec
  les variables extraites dans le scope (`extract($data, EXTR_SKIP)`) et **capture**
  la sortie grâce à la tamporisation (`ob_start()` / `ob_get_clean()`) : le HTML
  produit devient une chaîne de caractères. C'est l'astuce centrale : « exécuter un
  fichier PHP et récupérer ce qu'il affiche ».
- `renderPage($template, $data)` : rend d'abord la page (partial), récupère aussi les
  messages flash (`Session::pullFlashes()`), puis rend `layout/app.php` en lui
  passant `$content` et `$flashes`. La page est donc **emboîtée** dans le gabarit
  commun (header/nav/footer).

Aucun moteur, aucune syntaxe nouvelle : les vues sont du PHP, et la seule fonction
qu'elles doivent connaître est `e()`.

### `Session.php` — l'emballage de la session

Accès `get`/`set`/`forget` à `$_SESSION` + deux mécanismes :

- **Les messages flash** : `flash('success', '...')` empile un message dans la
  session ; `pullFlashes()` les récupère **et les efface** — ils s'affichent une
  fois, à la requête suivante. C'est ce qui permet d'afficher « Compte créé ! »
  après une redirection (le message survit à la redirection, puis disparaît).
- **`regenerate()`** (`session_regenerate_id(true)`) : change l'identifiant de
  session au login — **défense contre la fixation de session** (un attaquant qui
  aurait placé un identifiant connu devient impuissant une fois l'identifiant
  changé).
- **`destroy()`** : efface `$_SESSION`, expire le cookie, détruit la session (logout).
- **`restart()`** (ajouté en phase 2) : détruit **puis** redémarre une session
  neuve — nécessaire quand on veut détruire la session existante (anti-fixation à la
  suppression de compte) mais garder la possibilité de poser un flash pour la
  réponse.

### `Csrf.php` — les jetons anti-CSRF

Le problème : un autre site web peut faire soumettre un formulaire de ton site à un
visiteur connecté (le navigateur envoie les cookies automatiquement). La parade : à
chaque session est associé un **jeton secret** (64 caractères hexadécimaux issus de
`random_bytes(32)`, donc cryptographiquement aléatoire) ; chaque formulaire le porte
dans un champ caché ; le serveur **refuse** tout POST dont le jeton ne correspond pas
(lecture via le champ `_csrf_token` ou l'en-tête `X-CSRF-Token` pour les appels
JavaScript). Un site étranger ne peut pas deviner le jeton (il ne peut pas lire une
page de ton site à cause de la same-origin policy), donc ne peut pas forger de
requête valide.

```php
return hash_equals((string) $token, $submitted);
```

`hash_equals` compare en **temps constant** : un attaquant qui mesure le temps de
réponse ne peut pas deviner le jeton caractère par caractère. Détail important :
la vérification est **sans état de sortie** identique que le jeton soit absent ou
faux — aucune information divulguée.

### `Validator.php` — la validation des formulaires

Un petit objet qu'on **chaîne** (`new Validator()->username(...)->email(...)`) et qui
accumule les erreurs champ par champ. Règles implémentées :

- `email` : `filter_var(..., FILTER_VALIDATE_EMAIL)` (la validation standard PHP) ;
- `username` : 3–20 caractères, uniquement `[A-Za-z0-9_]` (regex) ;
- `password` : ≥ 8 caractères, avec au moins une minuscule, une majuscule et un
  chiffre (trois regex) ;
- `required`, `maxLength` (ajoutée en phase 4 avec `mb_strlen`, qui compte les
  **caractères** UTF-8 et pas les octets — indispensable pour ne pas pénaliser les
  accents : « é » vaut 2 octets) ;
- `addError`/`passes`/`fails`/`errors`.

Principe clé : la validation est **côté serveur**. Les attributs HTML
(`required`, `minlength`) ne sont que des indications de confort ; tout est re-vérifié
en PHP.

## 1.13 `src/routes.php` et `public/index.php`

`src/routes.php` est la **table des routes** : une liste lisible de
`méthode + motif + contrôleur + action`. À la phase 1 il n'y avait que `GET /` vers
un `HomeController` (page d'accueil) — depuis la phase 4, `/` est la galerie et
`HomeController` a été supprimé. Le fichier retourne un `Router` configuré ; les
commentaires le réorganisent par phase (galerie, inscription, login, compte, éditeur,
likes, commentaires).

`public/index.php` est le **front controller** — le seul fichier PHP exposé. Lu dans
l'ordre :

1. `require bootstrap.php` : autoloader, erreurs, session.
2. `Request::fromGlobals()` : construire la requête.
3. `View::share(...)` : chaque vue recevra `currentUser` (via `Auth::user()`, phase 2)
   et `csrfToken`.
4. `$router = require .../src/routes.php` puis `$router->match(...)`. Si
   `NotFoundException` → page 404 **dans le layout complet** (statut 404).
5. Instancier le contrôleur, appeler l'action avec `($request, $params)`.
6. Si l'action lève `NotFoundException` → 404 ; si elle lève autre chose → log fichier
   + `pages/500.php` **sans layout** (une page 500 minimale : si le moteur de vue est
   en cause, l'utiliser pour afficher l'erreur créerait une récursion infinie).
7. `$response->send()`.

Résumé en une phrase : *bootstrap → requête → route → contrôleur → réponse → send*.

## 1.14 Les vues de la phase 1

- `src/Views/layout/app.php` — le gabarit commun : `<head>` (charset, viewport, titre,
  favicon `/favicon.png`, CSS), header avec la marque et la **navigation** (liens
  Editor/Account/Logout **ou** Login/Register selon `$currentUser` — le logout est un
  **formulaire POST** avec jeton CSRF, jamais un lien GET), la zone des messages
  flash (échappés), `<main>` avec `<?= $content ?>`, le footer. La ligne
  `<?php if (!empty($headMeta)) { echo $headMeta; } ?>` a été ajoutée en phase 9 pour
  les balises Open Graph.
- `src/Views/pages/404.php` — page introuvable, rendue dans le layout.
- `src/Views/pages/500.php` — HTML autonome minimal, hors layout (voir ci-dessus).
- `src/Views/pages/home.php` — la page d'accueil d'origine, **supprimée en phase 4**
  au profit de la galerie.

### Le CSS (`public/assets/css/app.css`)

781 lignes de CSS **écrit à la main**, organisé par sections commentées (Reset,
Header, Main, Footer, Flash, Gallery, Image detail, Comments, Auth pages, Account,
Editor, Responsive...). Contraintes : **flexbox uniquement** (pas de `display: grid`,
pas de `gap` en flexbox, pas de variables CSS `var()`, pas de `position: sticky`, pas
de `aspect-ratio`) car ces features n'existent pas dans Firefox 41 / Chrome 46. La
grille de la galerie est donc un flexbox `flex-wrap: wrap` avec des **marges
négatives** pour simuler la gouttière, 2 images par ligne sur mobile, 3 en
`min-width: 640px`. Le tout est **mobile-first** : la règle de base cible le petit
écran, la media query enrichit les grands écrans.

## 1.15 `config/overlays.php` — la liste blanche des overlays

```php
return [
    1 => 'cat.png',
    2 => 'sun.png',
    3 => 'tree.png',
    4 => 'volcano.png',
];
```

Un fichier qui **retourne un tableau** (c'est la façon PHP de faire un fichier de
config : `require` renvoie le tableau). Le client n'envoie **jamais** un nom de
fichier — seulement un **id** (1 à 4). Le serveur traduit id → fichier. Ce petit
indirection est une protection : un attaquant ne peut pas demander
`overlay=../../.env` (traversée de chemin), puisque seuls les quatre ids numériques
existent et sont mappés sur des noms choisis par nous. (Utilisé à partir de la
phase 3.)

## 1.16 `NOTES.md` et la politique de « silence console »

`NOTES.md` documente : la justification de chaque outil non évident (`msmtp`, GD,
pdo_pgsql, finfo, mbstring), tous les leviers essayés pour taire les démarrages de
conteneurs, et les lignes de démarrage **acceptées** (le bannière initdb de Postgres
au premier démarrage d'un volume, les lignes `LOG` de Postgres que
`log_min_messages` ne peut pas filtrer car elles sont de rang supérieur au seuil,
etc.). La règle : une ligne qui ne peut pas être tue n'est acceptée **que** si (a) on
a essayé de la taire, (b) elle n'apparaît qu'au démarrage, jamais pendant le
fonctionnement, et (c) elle est listée dans NOTES.md avec sa source. Tout ce qui
apparaît **pendant** l'exécution (erreurs, warnings, lignes d'accès) est interdit et
a été éliminé (c'est l'objet des réglages `access_log off`, `access.log = /dev/null`,
`2>/dev/null`, gestionnaires d'erreurs, etc.).

---

# Phase 2 — Les comptes

**Objectif de la phase.** Tout ce qui touche à l'utilisateur : inscription avec email
de confirmation, connexion, déconnexion, mot de passe oublié, page compte
(pseudo/email/mot de passe/préférences de notification/suppression du compte). Et
pour que la confirmation email marche : toute une **couche mail** construite de zéro,
sans bibliothèque.

Ordre de lecture : l'infrastructure corrigée (entrypoint, php.ini), puis la couche
mail de bas en haut (MessageBuilder → MsmtpMailer → AppMailer), les templates
d'emails, le cœur (SiteUrl, Auth, Session), les modèles (User, PasswordReset), les
contrôleurs (AuthController, AccountController), les vues, le câblage.

## 2.1 Comment un email part-il de PHP ? (théorie)

Le trajet complet d'un email dans ce projet :

```
code PHP: AppMailer        ->  MsmtpMailer        ->  mail()         ->  msmtp            ->  serveur SMTP externe (par ex. Gmail)
   (construit le message      (prépare To,           (fonction PHP      (programme installé     (le vrai relais : il
    HTML + lien du jeton)      sujet, en-têtes)       standard)          dans l'image Docker)    envoie l'email au destinataire)
```

- **`mail()`** est la fonction standard PHP. Elle ne sait **pas** envoyer d'email
  elle-même : elle exécute le programme indiqué par `sendmail_path` et lui confie le
  message. Ici `sendmail_path = "/usr/bin/msmtp -t"` (voir php.ini).
- **msmtp** est un petit programme dont le seul travail est de **relayer** vers un
  serveur SMTP (le protocole d'envoi d'emails). `-t` veut dire « lis les
  destinataires dans les en-têtes du message ». Sa configuration
  (`/etc/msmtprc`, générée par l'entrypoint) contient l'hôte SMTP, le port, le login,
  le mot de passe, et gère le **chiffrement TLS** tout seul — aucune extension réseau
  PHP n'est nécessaire, ce qui colle à la contrainte « bibliothèque standard
  uniquement ».
- **SMTP**, c'est le protocole. Deux variantes de ports : 465 (TLS dès la connexion,
  `SMTP_SECURE=ssl`) ou 587 (chiffrement négocié après connexion, STARTTLS,
  `SMTP_SECURE=starttls`). Le `case` de l'entrypoint règle
  `tls_starttls on/off` en conséquence.
- **Le format d'un email (MIME)** : un email n'est pas juste du texte — c'est un
  document structuré : des **en-têtes** (`From:`, `Subject:`, `Date:`, `MIME-Version:`,
  `Content-Type:`, `Message-ID:`), une ligne vide, puis le **corps**. Le corps peut
  être en `multipart/alternative` : deux versions du même contenu (texte brut + HTML)
  séparées par une **frontière** (boundary), et le lecteur de mail affiche celle
  qu'il préfère. Les contenus non-ASCII (sujet avec accents, corps UTF-8) sont
  encodés (base64 ; les sujets selon la norme RFC 2047 : `=?UTF-8?B?...?=`).

## 2.2 `src/Services/Mail/MessageBuilder.php` — l'artisan des en-têtes

La classe partagée qui construit les morceaux communs à tous les emails. Deux points
de sécurité majeurs :

**L'injection d'en-têtes.** Un en-tête email, c'est une ligne `Nom: valeur` terminée
par un retour à la ligne. Si une valeur contrôlée par l'utilisateur contient un
retour à la ligne, l'attaquant **crée des en-têtes supplémentaires** — par exemple
des `Bcc:` pour spammer, ou un `To:` falsifié. Parade systématique :

```php
public static function sanitizeHeaderValue(string $value): string
{
    return str_replace(["\r", "\n", "\0"], '', $value);
}
```

Tout ce qui finit dans un en-tête (adresse, nom, sujet) passe par là.

**Le sujet encodé (RFC 2047).** Un sujet avec accents ne peut pas partir en UTF-8
brut dans un en-tête (limité à l'ASCII historiquement) :
`encodeSubject()` base64-encode si le sujet contient des octets ≥ 0x80, sous la forme
`=?UTF-8?B?<base64>?=` que tous les lecteurs savent décoder.

Le reste : `formatAddress()` (« Nom <adresse> »), `headers()` (From, MIME-Version,
Date, **Message-ID** généré aléatoirement — le domain pris de l'expéditeur, comme la
norme l'attend, sinon les fournisseurs classent le mail en spam), et `body()` qui
assemble le `multipart/alternative` (partie texte **avant** la partie HTML —
l'ordre préféré est le dernier par convention MIME, le lecteur choisit).

## 2.3 `src/Services/Mail/MsmtpMailer.php` — le seul endroit qui appelle `mail()`

`send($to, $subject, $htmlBody, $textBody = null)` :

1. assainit le destinataire et encode le sujet ;
2. construit les en-têtes via MessageBuilder — soit simple (`text/html` seul), soit
   `multipart/alternative` avec une **frontière aléatoire** ;
3. appelle `mail()` et **renvoie true/false**. `mail()` renvoie false quand msmtp
   échoue : c'est ainsi que l'appelant sait qu'un envoi a raté (sans jamais casser la
   requête).

Règle d'architecture : **aucun autre fichier du projet n'a le droit d'appeler
`mail()`**. Tout passe par AppMailer → MsmtpMailer. Un seul endroit où le format
d'email est manipulé.

> **Note historique.** Le journal de bord de la phase 2 mentionnait deux fichiers
> supplémentaires : `Mailer.php` (une interface) et `MailerFactory.php` (choix du
> « driver » selon `MAIL_DRIVER`). Ils ont existé pendant la phase 2, prévus pour
> supporter plus tard un driver SMTP pur-PHP — mais ce driver (phase 8) a été sauté
> par décision, et l'indirection a été retirée du code final : aujourd'hui
> `AppMailer` instancie directement `MsmtpMailer`. Le fichier `MAIL_DRIVER` n'existe
> plus dans le `.env`.

## 2.4 `src/Services/Mail/AppMailer.php` — les trois emails du site

C'est l'API « métier » du mail : trois méthodes, une par email que le site envoie.

- `sendVerification($to, $username, $token, $baseUrl)` — construit le lien
  `/verify?token=<token>` et rend `emails/verification.php` (HTML) +
  `emails/verification_text.php` (texte) ;
- `sendPasswordReset(...)` — lien `/reset-password?token=...`, templates
  `reset_password*` ;
- `sendCommentNotification(...)` (ajoutée en phase 4) — lien `/images/<id>`,
  templates `comment_notification*`.

Mécanique commune : le lien est construit sur la **base URL** résolue par `SiteUrl`
(voir 2.5), les deux templates sont rendus via `View::renderPartial` **exactement
comme des vues de page** (mêmes outils, même échappement `e()`), et le tout part par
MsmtpMailer. Chaque méthode renvoie le booléen de succès : à l'appelant de logger
sans casser la requête.

Les templates HTML (par ex. `src/Views/emails/verification.php`) sont un tableau HTML
centré (les webmails préfèrent les tables aux divs), avec le nom d'utilisateur
échappé, un gros bouton-lien, le lien en clair en dessous (si le bouton ne marche pas),
et une phrase « ignorez cet email si ce n'était pas vous ».

## 2.5 `src/Core/SiteUrl.php` — l'URL de base des liens d'email

Le problème résolu ici s'appelle le **host header poisoning**. Quand un visiteur
charge `http://192.168.1.14/login`, il peut envoyer un en-tête `Host:` falsifié
(`Host: evil.com`). Si le site construisait ses liens d'email avec cet en-tête, le
lien de confirmation partirait vers `evil.com/verify?token=...` — le jeton secret
serait livré à l'attaquant.

La solution retenue (décision utilisateur, documentée dans NOTES.md) : le lien
utilise le `Host` de la requête **uniquement s'il est sur une liste blanche** —
l'hôte de `APP_URL` plus les motifs de `APP_ALLOWED_HOSTS` (variable ajoutée au-delà
de la spec, par ex. `192.168.*.*` ; chaque `*` couvre exactement un label DNS, donc
`192.168.*.*` ne peut pas matcher `192.168.1.20.evil.com`). Le `Host` est d'abord
vérifié par une regex stricte (lettres/chiffres/points/tirets + port optionnel) qui
rejette toute injection CRLF. Tout le reste retombe sur `APP_URL`. L'intérêt :
pouvoir tester le site depuis la VM, le portable, etc., sans éditer APP_URL à chaque
fois — sans ouvrir la porte à l'empoisonnement.

## 2.6 `src/Core/Auth.php` + `Session::restart()` — l'état « connecté »

`Auth` gère l'identité de la requête courante :

- `login($user)` : **régénère l'identifiant de session** (anti-fixation) puis stocke
  `user_id` dans la session ;
- `user()` : chargé une seule fois par requête (cache). Lit `user_id` dans la
  session, va chercher la ligne en base, et **vérifie `is_verified`** — un compte
  supprimé ou non vérifié est traité comme déconnecté (l'id est retiré de la
  session). Renvoie `null` pour un visiteur ;
- `check()` : quelqu'un est-il connecté ?

C'est la seule source de vérité : `$currentUser` partagé aux vues vient de là (le
front controller appelle `Auth::user()`).

## 2.7 Les modèles `User.php` et `PasswordReset.php`

Rappel d'architecture : **tout le SQL vit dans les modèles**, jamais dans les
contrôleurs ni les vues. Chaque méthode = une requête, toujours préparée.

### `User.php` (exemples commentés)

```php
$stmt = Database::connection()->prepare(
    'SELECT * FROM users WHERE lower(username) = lower(?)'
);
$stmt->execute([$username]);
```

Le `?` est un **paramètre lié** : la valeur ne peut jamais casser la requête (pas
d'injection SQL possible — la requête et les données voyagent séparément). La
recherche par `lower(...)` des deux côtés rend la correspondance insensible à la
casse, en cohérence avec les index uniques du schéma.

Méthodes notables :

- `create(...)` : INSERT ; le contrôleur a déjà haché le mot de passe **et** le
  jeton de confirmation (`hash('sha256', $token)`) ; renvoie le nouvel id
  (`lastInsertId('users_id_seq')`).
- `usernameTakenByOther($username, $selfId)` : unicité **en excluant sa propre
  ligne** (`AND id <> ?`) — sinon changer son profil sans rien modifier échouerait
  « pseudo déjà pris » par soi-même.
- `markVerified($id)` : `is_verified = true` **et** `verification_token_hash = NULL`
  — le jeton ne peut plus matcher, il est consommé même si l'email est rejoué.
- `updateNotifications(...)` : le point technique intéressant du fichier —
  ```php
  $stmt->bindValue(1, $onComment, PDO::PARAM_BOOL);
  ```
  Avec les préparations natives (`EMULATE_PREPARES = false`), un booléen PHP `false`
  serait envoyé comme chaîne vide, que Postgres refuse (« invalid input syntax for
  type boolean »). Le type **doit** être déclaré explicitement. C'est le genre de
  détail que seule l'explication documente (il est aussi dans NOTES.md).

### `PasswordReset.php` — la sécurité du « mot de passe oublié »

Les trois invariants : **aléatoire** (`random_bytes(32)` côté contrôleur), **hashé en
base** (`token_hash`, SHA-256), **expirant** (`expires_at = now() + interval '1
hour'`, calculé par Postgres lui-même). Et surtout :

```php
public static function claim(int $id): bool
{
    $stmt = Database::connection()->prepare(
        'UPDATE password_resets SET used_at = now() WHERE id = ? AND used_at IS NULL'
    );
    $stmt->execute([$id]);
    return $stmt->rowCount() === 1;
}
```

**L'usage unique est atomique.** Le `UPDATE ... WHERE used_at IS NULL` ne réussit
qu'une fois : si deux requêtes concurrentes présentent le même jeton, la base ne
laisse gagner que la première (`rowCount()` vaut 1 pour elle, 0 pour l'autre). Le
toggle « liker » de la phase 4 repose sur le même genre d'astuce.

`create()` commence par **invalider tout jeton précédent** du même utilisateur
(dans une transaction : `beginTransaction` / `commit` / `rollBack`) — un seul lien
de reset actif par compte, ce qui limite la portée d'un vieux email divulgué.

## 2.8 `src/Controllers/AuthController.php` — le gros morceau

C'est le plus gros contrôleur du projet. On le lit fonction par fonction.

### Inscription (`registerForm` / `register`)

Le POST fait, dans l'ordre : **CSRF** (sinon flash « session expirée » et retour au
formulaire) → **validation** (`Validator` : username, email, password) →
**unicité** (deux requêtes) → si erreur, re-rendu du formulaire **avec les valeurs
saisies** (`old`) et les erreurs par champ → sinon création :

```php
$token = bin2hex(random_bytes(32));                 // 64 hex, envoyé par email
User::create($username, $email,
    password_hash($password, PASSWORD_DEFAULT),     // bcrypt
    hash('sha256', $token));                       // seul le hash en base
```

puis envoi de l'email de confirmation. Si l'envoi échoue : log + flash d'erreur, mais
**le compte existe** (la requête ne casse pas). Note la course possible : deux
inscriptions simultanées avec le même pseudo peuvent passer toutes deux le contrôle
d'unicité ; l'**index unique** de la base fait alors échouer le second INSERT avec
l'erreur Postgres 23505 (duplicate key), attrapée proprement par le `catch
(PDOException)` — la base est le dernier rempart, le code le premier.

### Vérification (`verify`)

`GET /verify?token=...` : hash le jeton reçu, cherche la ligne correspondante,
re-compare avec `hash_equals`, puis `markVerified`. **Un jeton absent, inconnu ou
déjà utilisé produit exactement la même réponse** — on ne révèle pas quels comptes
existent.

### Connexion (`login`) — la lutte contre l'énumération

L'exigence : « identifiant inconnu » et « mauvais mot de passe » doivent être
**indiscernables**, en message **et en timing**. Sans précaution, mesurer le temps de
réponse suffit à savoir si un pseudo existe (vérifier un bcrypt coûte ~100 ms ;
ne pas vérifier, quasi 0). D'où :

```php
private const DUMMY_HASH = '$2y$10$...';  // hash de "camagru-login-dummy"
if ($user === null) {
    password_verify($password, self::DUMMY_HASH);  // dépense le même temps
    return $this->loginFailed();                  // même message générique
}
```

Si les identifiants sont bons : `password_needs_rehash` (si les paramètres de bcrypt
ont changé depuis, re-hacher transparent), puis `Auth::login($user)` (rotation de
l'identifiant de session) et redirection. Un compte non vérifié ne peut pas se
connecter (message dédié — acceptable : seul le vrai propriétaire a les identifiants
corrects).

### Déconnexion (`logout`)

POST + CSRF, partout dans le header. `Auth::logout()` puis `Session::restart()`
(détruit tout, redémarre une session propre qui ne porte que le flash « You have
been logged out »).

### Mot de passe oublié (`forgotPassword`)

Le POST ne **révèle jamais** si l'email existe : même message et même redirection
dans tous les cas (« If an account exists... »). Si le compte existe : création du
jeton + envoi de l'email ; un échec d'envoi est loggé et ne change rien à la réponse.
Le lien mène à `GET /reset-password?token=...` (formulaire seulement si le jeton est
valide), et le POST final : validation du nouveau mot de passe → **claim atomique**
du jeton (usage unique, même en concurrence) → changement du hash → invalidation de
tous les autres jetons du compte.

## 2.9 `src/Controllers/AccountController.php` — la page compte

Une page, quatre formulaires, tous POST vers `/account` avec un champ caché
`action` qui dit lequel (`profile`, `password`, `notifications`, `delete`) — un
seul point de routage interne, mais chaque formulaire a son CSRF et sa logique.

- **Profil** : changement de pseudo/email, mêmes règles que l'inscription, unicité
  hors sa propre ligne, email effectif **immédiatement** sans re-vérification
  (choix de la spec), course 23505 attrapée comme à l'inscription.
- **Mot de passe** : exige le **mot de passe actuel** (re-authentification : une
  session volée ne peut pas verrouiller le compte en silence), puis même règle que
  l'inscription ; les jetons de reset en circulation sont invalidés.
- **Notifications** : deux cases à cocher. Détail HTML : une case **non cochée
  n'est pas envoyée du tout** dans le POST — le contrôleur teste la **présence**
  (`$request->post('notify_on_comment') !== null`).
- **Suppression de compte** (fonctionnalité demandée au-delà de la spec d'école 42) :
  mot de passe exigé, puis suppression de la ligne — images, likes, comments et jetons
  partent par **cascade SQL** — puis suppression des **fichiers** uploadés un par un
  (`Image::filenamesByUser` + `Image::removeFile`, meilleur effort : un fichier
  récalcitrant est loggé, jamais bloquant), puis destruction complète de la session
  (`Session::restart`). Le code collecte les noms de fichiers **avant** de supprimer
  la ligne : après la cascade, il n'y a plus rien à lire dans la table.

## 2.10 Les vues d'authentification

`register.php`, `login.php`, `forgot_password.php`, `reset_password.php`,
`account.php` — même gabarit : titre, liste d'erreurs (échappées), formulaire avec
champ CSRF caché, labels sur chaque input, indices de format sous les champs,
liens croisés entre pages. Tout ce qui vient de l'utilisateur ou de la base passe par
`e()`. L'attribut `autocomplete` (`new-password`, `current-password`) aide le
navigateur sans rien exiger.

## 2.11 `COMPATIBILITY.md` entrée 4 — l'avertissement Firefox des mots de passe

Firefox 41 affiche dans **sa** console : « Password fields present on an insecure
(http://) page » pour tout `<input type="password">` servi en HTTP. Ce n'est pas un
bruit causé par le code — c'est une heuristique du navigateur, inévitable tant que
le site est en HTTP (choix de la spec pour localhost). La décision documentée :
accepté, listé dans NOTES.md et COMPATIBILITY.md (entrée 4, statut `confirmed`),
car il n'existe aucune parade dans les contraintes du projet. C'est le seul bruit
console accepté côté navigateur.

---

# Phase 3 — L'éditeur et le pipeline d'images

**Objectif de la phase.** Le cœur « fun » du projet : une page `/editor` réservée aux
connectés qui combine **webcam** (JavaScript) et **upload de fichier** (form HTML),
avec superposition d'overlay **côté serveur** (GD), stockage dans le volume
`uploads`, et une colonne latérale avec les miniatures de ses propres photos et leur
suppression.

Ordre de lecture : d'abord le service image (le cœur sécuritaire), puis le modèle,
les contrôleurs, la vue, et enfin le JavaScript — le JS n'est que la vitrine d'un
pipeline qui marche déjà sans lui.

## 3.1 La sécurité des uploads — pourquoi chaque étape existe

Un upload est le trou d'attaque classique d'une appli web. Les attaques et les
parades, telles qu'implémentées dans `src/Services/ImageComposer.php` :

| Attaque | Parade dans le code |
|---|---|
| Fichier PHP renommé en `.png` (web shell) | `finfo` lit le **contenu** et détecte le vrai type ; un fichier qui n'est pas vraiment un PNG/JPEG est rejeté **avant** tout décodage. Et même accepté : l'image est **re-encodée** par GD, donc tout code embarqué disparaît. Et nginx refuse d'exécuter du PHP dans `/uploads/`. Trois remparts indépendants. |
| « Bombe de décompression » (petit PNG qui décode en géant et épuise la mémoire) | `getimagesizefromstring` lit les **dimensions** dans l'en-tête **avant** tout décodage GD ; au-delà de 2500 x 2500 px, rejet. |
| Traversée de chemin (`overlay=../../../etc/passwd`) | L'overlay est désigné par **id** résolu via la liste blanche `config/overlays.php`. |
| Nom de fichier malveillant (`../../x.php`) | Le nom stocké est **généré par le serveur** : `bin2hex(random_bytes(16)).png`. Rien du client n'atteint le système de fichiers. |
| Fichier trop volumineux | Limite 5 Mo mesurée par **PHP** (`$file['size']`), pas par une valeur client ; codes d'erreur d'upload vérifiés ; alignement nginx/PHP. |
| Fichier qui ne vient pas de cette requête | `is_uploaded_file()` refuse un chemin qui n'a pas été réellement uploadé par PHP. |

## 3.2 `src/Services/ImageComposer.php` pas à pas

Une classe statique avec une méthode publique : `compose($file, $overlayId)` — «
reçois une entrée `$_FILES` et un id d'overlay, rends-moi un nom de fichier ».
Étapes :

1. **`readUpload`** : vérifie `error` (constant `UPLOAD_ERR_*` de PHP — `OK`,
   `INI_SIZE`, `FORM_SIZE`...), `is_uploaded_file`, la taille 1..5 Mo, puis lit les
   octets.
2. **`decode`** : `finfo` avec `FILEINFO_MIME_TYPE` → seulement `image/png` ou
   `image/jpeg` ; `getimagesizefromstring` → type + **dimensions plafonnées** (cap
   avant décodage, cf. tableau) ; puis `imagecreatefromstring` (GD décode pour de
   bon). Les `@` (silence PHP) garantissent qu'un fichier corrompu devient **notre**
   exception propre, pas un warning PHP.
3. **`loadOverlay`** : `$overlays = require APP_ROOT . '/config/overlays.php'` ; si
   l'id n'existe pas → `ImageException('Please choose a valid overlay.')`. Puis
   charge le PNG (`imagecreatefrompng`).
4. **`composite`** — le dessin :
   - un **canvas truecolor neuf** de la taille de la photo. C'est l'étape de
     **ré-encodage** : GD n'y copie ni métadonnées, ni EXIF, ni payload — l'image
     produite est 100 % générée ;
     `imagealphablending($out, false)` + `imagesavealpha($out, true)` pendant le
     remplissage (pour que le canal alpha du résultat soit correct), puis
     `imagecopyresampled` y copie la photo ;
   - l'overlay est ensuite **mis à l'échelle** pour tenir dans l'image (facteur
     `min(baseW/ovW, baseH/ovH)`), **centré** (`floor((base - new) / 2)`), et dessiné
     avec le blending **allumé** cette fois : les pixels transparents de l'overlay
     laissent la photo transparaître au lieu de l'écraser — c'est tout l'intérêt du
     canal alpha du PNG.
5. **`save`** : nom aléatoire + `imagepng` dans `APP_UPLOAD_DIR` (le volume) ;
   `imagedestroy` des ressources GD (libérer la mémoire est explicite avec GD).

Les erreurs sont toutes des `ImageException` (`src/Services/ImageException.php`) —
une exception dont les messages sont **sûrs à montrer à l'utilisateur** (aucun chemin,
aucune requête, aucun détail interne dedans).

## 3.3 `src/Models/Image.php`

Le modèle de la table `images` : `findById`, `create`, `delete`, `allByUser` (la
colonne latérale de l'éditeur, plus récentes d'abord, `id DESC` départage les
ex-aequo de la même seconde), `filenamesByUser` (pour la suppression de compte),
et surtout **`removeFile`** — le rappel du fichier sur le volume, que la cascade
SQL ne peut pas faire. `countAll`, `page` et `findWithAuthor` arrivent en phase 4.

## 3.4 `src/Controllers/EditorController.php` — un endpoint, deux publics

`GET /editor` : réservé aux connectés (sinon flash + redirection `/login` — exigence
de la spec : redirection **amicale**, pas une page d'erreur). Passe à la vue la liste
blanche des overlays et les images de l'utilisateur.

`POST /editor/capture` : le point original de la phase. **Deux sources d'image**
(la webcam JS et le formulaire d'upload sans JS) arrivent **au même endroit**, avec
les mêmes noms de champs (`photo`, `overlay`) — même pipeline `ImageComposer`,
exigence de la spec. Seule la **réponse** diffère, choisie par la présence de
l'en-tête `X-Requested-With` (envoyé par notre JS) :

- requête **XHR** → `Response::json(['id' => ..., 'url' => ...])` (le JS insère la
  miniature) ;
- formulaire **classique** → flash + redirection vers `/editor` (l'éditeur marche
  donc intégralement sans JavaScript).

Ordre des gardes : auth → CSRF → fichier présent → pipeline (id d'overlay validé
**dedans**, par la liste blanche) → insert en base. Rejet → JSON 422 ou flash.

## 3.5 `src/Controllers/ImageController.php` — supprimer SA photo

`POST /images/{id}/delete` : auth → CSRF → l'image existe (sinon **404**, pas une
erreur : pour ce visiteur, elle n'existe pas) → **contrôle de propriété**
(`$image['user_id'] !== $user['id']` → refus, redirection) → suppression de la ligne
(les likes/commentaires partent en cascade) → suppression du **fichier** (meilleur
effort, loggé si échec) → flash. L'ownership est le point de sécurité : connaître
l'id d'une image d'autrui ne suffit pas.

## 3.6 `src/Views/pages/editor.php`

Deux sections côte à côte (flexbox) :

- **principale** : la `<video>` de la webcam (id `editor-video`), le canvas de
  l'aperçu d'overlay (bonus phase 9, caché au départ), le message « pas de webcam »
  (caché au départ), le **formulaire** (CSRF caché ; les overlays en **boutons
  radio**, chacun portant `data-overlay-src` — l'attribut par lequel le JS trouvera
  le PNG pour l'aperçu), le champ `file` et le bouton Upload, le bouton « Take a
  photo » (qui, lui, est un `<button type="button">` : il ne soumet rien, le JS
  l'écoute), une ligne de statut ;
- **latérale** : la liste des photos de l'utilisateur, chacune avec son formulaire
  de suppression.

Détail d'infrastructure à connaître : le `<script src="/assets/js/editor.js?v=4">`.
Comme nginx sert `/assets/` avec `expires 1h`, le navigateur **cache** le JS une
heure. À chaque modification du JS, il faut **incrémenter la version** (`?v=N`) pour
casser le cache — la note est écrite dans la vue elle-même, et le `?v=4` actuel est
le résultat de trois corrections successives.

## 3.7 `public/assets/js/editor.js` — le JavaScript de compatibilité

C'est le fichier client le plus riche du projet, et il est écrit en **ES5 strict**
(`'use strict'`, `var` et `function`, aucune flèche, aucun template literal) pour
Firefox 41 / Chrome 46. Structure : une **IIFE** — `(function () { ... }());` — qui
isole les variables du reste de la page (il n'y a pas de modules en ES5).

Sous-fonction par sous-fonction :

- **Le déverrouillage des boutons** : `refreshButtons()` active « Take a photo » et
  « Upload » seulement quand un overlay est coché (le `change` des radios **bulle**
  jusqu'au formulaire, un seul écouteur). Sans JS, les boutons restent simplement
  actifs et le serveur valide l'overlay — l'éditeur marche dans les deux mondes.
- **Le wrapper webcam** (`getWebcam`) : essaie dans l'ordre
  `navigator.mediaDevices.getUserMedia` (moderne, renvoie une promesse), puis les
  vieilles versions préfixées à **callbacks** (`navigator.getUserMedia`,
  `webkitGetUserMedia`, `mozGetUserMedia`), puis échoue proprement. Règle du projet :
  jamais d'appel direct à `getUserMedia` — tout passe par ce wrapper
  (COMPATIBILITY.md entrée 2 : Chrome 46 n'a pas `navigator.mediaDevices`).
- **L'attachement du flux** (`attachStream`) : détection de fonctionnalité —
  `srcObject` (moderne), sinon `mozSrcObject` (Firefox 41), sinon
  `video.src = URL.createObjectURL(stream)` (vieux Chrome). Entrée 1 du
  COMPATIBILITY.md.
- **L'origine sécurisée** (`isSecureOrigin`) : Chrome (même actuel) refuse la webcam
  hors HTTPS/localhost — silencieusement. Si l'origine n'est pas sûre, le message
  affiché le **dit** (« open the editor at http://localhost... You are currently
  viewing from "192.168.1.14" ») au lieu d'un « not available » mystérieux. Entrée 6.
- **La capture** : `captureFrame()` dessine l'image de la vidéo sur un `<canvas>`
  puis `canvas.toDataURL('image/png')` — le frame devient une longue chaîne
  `data:image/png;base64,...`. `dataUrlToBlob()` la convertit en **Blob** (un objet
  fichier en mémoire) : `atob()` décode le base64 en « chaîne d'octets »,
  `Uint8Array` reconstruit les octets, `new Blob(...)`. **Pourquoi si contourné ?**
  Parce que `canvas.toBlob()` (qui ferait ça en une ligne) n'existe qu'à partir de
  Chrome 50 — entrée 3.
- **L'envoi** (`sendPicture`) : `FormData` (paires champ/valeur, dont `photo` = le
  Blob et `overlay` = l'id), transport par **XMLHttpRequest** avec les en-têtes
  `X-CSRF-Token` (le Csrf.php serveur le lit) et `X-Requested-With` (le serveur
  répondra JSON). Réponse 200 + `url` → succès ; sinon → message d'erreur du serveur
  affiché dans la ligne de statut. **Pourquoi XHR et pas `fetch` ?** Parce que sur
  Firefox 41, un `fetch` avec FormData+Blob **ne terminait jamais** : la promesse
  rejetait sans erreur console et le serveur ne recevait rien. C'est la plus grosse
  découverte de compatibilité du projet — entrée 5, statut `fixed` après re-test en
  VM.
- **La miniature** (`addThumbnail`) : après une capture réussie, le JS fabrique en
  mémoire un `<li>` complet (image + formulaire de suppression avec CSRF) et
  l'insère **en tête** de la colonne — la photo apparaît sans recharger la page.
  Tout par `createElement`/`appendChild` (jamais `innerHTML` avec des données : pas
  de surface d'injection).
- **Aucun `console.log`** : les statuts vont dans la ligne de statut ; les cas
  « gérés » (pas de webcam, refus) n'écrivent rien dans la console, comme l'exige la
  politique de silence.

### Les six entrées de `COMPATIBILITY.md` (toutes `fixed`)

1. `srcObject` absent (Firefox 41 = `mozSrcObject`, Chrome 46 < 52) ;
2. `navigator.mediaDevices` absent sur Chrome 46 → wrapper préfixé ;
3. `canvas.toBlob` absent sur Chrome 46 → `toDataURL` + conversion manuelle ;
4. avertissement Firefox « password fields on insecure page » — inévitable, accepté ;
5. `fetch` + FormData + Blob en échec silencieux sur Firefox 41 → XHR partout (c'est
   pour ça que **tout** le JS du projet utilise XHR et jamais fetch) ;
6. webcam refusée par Chrome sur origine HTTP non-localhost → message explicite +
   test en VM via redirection de port pour que le site soit vu comme
   `http://localhost`.

Le fichier `COMPATIBILITY.md` est la **mémoire** de ces problèmes : colonnes
feature/navigateur/symptôme/workaround/statut (`expected` → `confirmed` → `fixed`),
jamais une entrée n'y est supprimée. À lire avant d'écrire une ligne de JS.

## 3.8 Le CSS de l'éditeur

La section « Editor » de `app.css` : la disposition principale/latérale en
flexbox (colonne sur mobile, côte à côte dès 640 px), la liste d'overlays en
petites cartes, les miniatures avec leur bouton Delete, et l'état caché
(`.is-hidden { display: none; }`) piloté par le JS via les noms de classe.

---

# Phase 4 — La galerie publique

**Objectif de la phase.** La galerie devient la **page d'accueil** (`GET /`) : toutes
les images, les plus récentes d'abord, **6 par page** ; une page de détail
(`GET /images/{id}`) ; le **like** (toggle) et le **commentaire**, réservés aux
connectés ; l'email de notification à l'auteur selon ses préférences.

## 4.1 La pagination, ou « comment ne jamais laisser `?page=` toucher le SQL »

Exigence : trier par `created_at DESC`, 6 par page, `?page=N` **validé** côté serveur,
`LIMIT`/`OFFSET` passés en requête préparée.

**Côté SQL** (`src/Models/Image.php::page`) :

```php
$stmt = Database::connection()->prepare(
    'SELECT i.id, i.filename, i.created_at, u.username,
            (SELECT COUNT(*) FROM likes l    WHERE l.image_id = i.id) AS like_count,
            (SELECT COUNT(*) FROM comments c WHERE c.image_id = i.id) AS comment_count
     FROM images i
     JOIN users u ON u.id = i.user_id
     ORDER BY i.created_at DESC, i.id DESC
     LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
```

Trois notions SQL à décortiquer :

- **`JOIN users u ON u.id = i.user_id`** : une **jointure**. Chaque ligne d'image est
  combinée avec la ligne de son auteur — on récupère le `username` sans faire une
  deuxième requête par carte. `i` et `u` sont des **alias** de table.
- **Les sous-requêtes entre parenthèses** : pour chaque image, un mini-`SELECT
  COUNT(*)` compte ses likes et ses commentaires, renommés en colonnes
  `like_count`/`comment_count`. Une seule requête sert toute la page.
- **`LIMIT ? OFFSET ?`** : `LIMIT 6` = au plus 6 lignes ; `OFFSET N` = sauter les N
  premières (page 3 → offset 12). Ils sont **liés en entiers**
  (`PDO::PARAM_INT`) — avec les préparations natives, un `LIMIT` reçu comme chaîne
  ferait rejeter la requête par Postgres ; et surtout, **aucune** valeur de page ne
  peut s'injecter, même validée, puisqu'elle est liée.

**Côté contrôleur** (`GalleryController::normalizePage`) :

- absent, non numérique (`ctype_digit`), zéro ou négatif → page 1 ;
- au-delà de la dernière page → ramené à la dernière page.

La valeur brute n'atteint donc **jamais** le SQL : seule un entier borné y arrive.
Le nombre total de pages vient de `Image::countAll()` (`SELECT COUNT(*) FROM
images`).

`Image::findWithAuthor($id)` est la même jointure + sous-requêtes pour une seule
image (`WHERE i.id = ?`) : la page de détail.

## 4.2 `src/Models/Like.php` — un toggle sans course

```php
public static function add(int $userId, int $imageId): void
{
    $stmt = Database::connection()->prepare(
        'INSERT INTO likes (user_id, image_id) VALUES (?, ?) ON CONFLICT DO NOTHING'
    );
    ...
}
```

`ON CONFLICT DO NOTHING` : si la ligne existe déjà (clé primaire composite), Postgres
**ignore** l'insertion au lieu de lever une erreur. Pourquoi c'est important :
`toggle()` fait `exists() ? remove() : add()` — deux requêtes. Si l'utilisateur
double-clique très vite, deux requêtes peuvent coexister ; grâce à la clé primaire,
le pire cas est un no-op, jamais un doublon ni une erreur 500. La base de données est
toujours le dernier rempart.

## 4.3 `src/Models/Comment.php`

`create` (INSERT ; le corps a été validé/trimé par le contrôleur), `allByImage`
(jointure avec `users` pour les pseudos, tri **chronologique** — l'ordre naturel de
lecture), et `countByImage` (le compteur affiché, utilisé aussi en phase 9).

## 4.4 Les contrôleurs de la galerie

### `GalleryController.php`

- `index()` (GET `/`) : calcule `totalPages`, normalise `page`, charge la page
  d'images, rend `pages/gallery.php`. (Depuis la phase 9, une branche XHR répond en
  JSON pour le défilement infini — voir phase 9.)
- `show()` (GET `/images/{id}`) : `findWithAuthor` → 404 si inconnu ; l'état « déjà
  liké » du visiteur courant (`Like::exists`) ; les commentaires avec date formatée
  par `formatDate` (« 2 Oct 2026, 14:05 », en UTC — pas de fuseau par utilisateur,
  donc un format déterministe et sans ambiguïté) ; depuis la phase 9, les liens de
  partage et les balises Open Graph.

### `LikeController.php`

L'ordre des gardes est le sujet de sécurité : **auth** (un visiteur est redirigé
vers `/login`, pas une erreur) → **CSRF** → **404 si l'image n'existe pas** →
`Like::toggle` (l'id utilisateur vient de la **session**, jamais du client) → flash +
redirection. Un POST forgé depuis un autre site ne passe pas le CSRF ; un like sur
l'image de quelqu'un d'autre est légal (c'est public) ; retirer le like de quelqu'un
d'autre est impossible (l'id de session gouverne la ligne).

### `CommentController.php`

Mêmes gardes, puis validation du corps : `required` + `maxLength(1000)` — la règle
ajoutée à `Validator` pour l'occasion, avec `mb_strlen` (comptage **UTF-8**, un
caractère accentué compte pour 1, pas pour 2 octets). Le corps est stocké tel quel
et échappé à l'affichage. Puis `Comment::create` et la notification (ci-dessous).
Décision assumée et documentée : en cas d'erreur de validation, le texte n'est **pas
re-rempli** dans le champ — le flash nomme le problème ; c'est le seul endroit du
projet où la simplicité a gagné sur le confort.

## 4.5 La logique de notification — les quatre branches

`CommentController::notifyAuthor` implémente l'exigence la plus fine de la spec :

```
auteur a "notify me on new comments" OFF            -> pas d'email
auteur : ON, commentateur = quelqu'un d'autre       -> email
auteur : ON, commentateur = l'auteur lui-même,
         "notify own comment" OFF                   -> pas d'email
auteur : ON, commentateur = l'auteur lui-même,
         "notify own comment" ON                    -> email
```

Autrement dit : le second réglage **n'a aucun effet tant que le premier est off**
(`notifyAuthor` sort immédiatement si `notify_on_comment` est faux). Et l'invariant
le plus important : **un échec d'envoi ne casse jamais la requête** — le commentaire
est déjà enregistré ; `mail()` faux → `app_log(...)` et c'est tout. L'utilisateur
final n'apprend jamais qu'un serveur SMTP a eu un souci.

## 4.6 Les vues `gallery.php` et `image.php`

- `gallery.php` : message « pas encore d'images » si vide ; la grille (devenue
  `partials/gallery_items.php` en phase 9) ; les liens Précédent/Suivant avec l'état
  courant « Page X of Y » (désactivés en `<span>` aux extrémités). Tous les noms
  d'auteur passent par `e()`, les compteurs par `(int)`.
- `image.php` : la photo (depuis `/uploads/`, `e_attr`), l'auteur et la date, le
  formulaire de like **ou** un lien vers `/login` pour un visiteur (jamais un bouton
  mort), le compteur « N like(s) » avec le pluriel géré en PHP, les commentaires —
  chacun rendu par `partials/comment.php` (phase 9), le corps échappé avec `e()` et
  **les retours à la ligne préservés par CSS** (`white-space: pre-wrap`) plutôt que
  par `nl2br` — et le formulaire de commentaire réservé aux connectés.

## 4.7 Suppression de `HomeController`

La galerie a **remplacé** la page d'accueil du squelette : `HomeController.php` et
`pages/home.php` ont été supprimés, la route `/` pointe désormais sur
`GalleryController::index`. C'est un exemple de « nettoyage complet » : aucune
référence morte ne subsiste (ni route, ni include, ni lien CSS).

---

# Phases 5 à 7 — Sécurité, compatibilité, validation finale

Ces trois phases n'ont pas ajouté de fonctionnalité : elles ont **vérifié et fini**
la partie obligatoire. Elles forment un seul commit dans l'histoire git
(« phase 5-6-7 »).

## 5 — La revue de sécurité finale

Exigence du processus : la partie obligatoire n'est « finie » qu'après. Conduite de
la revue (voir `PLAN.md`, phase 5) :

- **Checklist de la spec section 9**, case par case : aucun mot de passe en clair ;
  toute sortie échappée (pseudos, commentaires, flash, emails) ; uploads contrôlés
  (taille, type réel, ré-encodage, nom aléatoire, pas de PHP dans `/uploads/`) ; aucun
  SQL concaténé (y compris `LIMIT`/`OFFSET`) ; CSRF sur chaque POST ; auth +
  ownership sur chaque route privée ; sessions/en-têtes conformes ; jetons aléatoires,
  hashés, à usage unique, expirants ; aucune page d'erreur ne fuit de détail interne.
- **Attaques « comme un évaluateur »**, lancées en curl contre la stack en marche :
  POST sans jeton CSRF, suppression de l'image d'un autre, XSS dans le pseudo et le
  commentaire, apostrophe dans les champs de login, fichier PHP renommé en `.png`
  uploadé, éditeur en visiteur, réutilisation d'un jeton de reset, accès direct à
  `src/`, `db/` et `.env` (réponses 404 — ils sont hors du `root` nginx).
- **Une vraie découverte corrigée** : Postgres 15+ écrit `LOG: checkpoint
  starting/complete` toutes les 5 minutes d'activité — de la sortie console **en
  cours d'exécution**, donc interdite. Fix : `log_checkpoints=off` ajouté à la
  commande du service `db` dans `docker-compose.yml` (NOTES.md documente le levier).
- **Une découverte acceptée** (décision utilisateur « A », documentée dans NOTES.md,
  section « Accepted deviations ») : les deux tout premiers commits du dépôt
  contenaient l'adresse d'envoi, l'hôte et le login SMTP en clair dans
  `PROJECT_CONTEXT.md` — aucun mot de passe, dépôt privé. L'alternative (réécrire
  l'historique git + force-push) a été jugée disproportionnée. L'arbre de travail
  actuel est propre et `.env` n'a jamais été commité.

## 6 — Le passage de compatibilité complet

L'utilisateur a **re-testé chaque page et chaque flow** dans Firefox 41 et Chrome 46
en VM : inscription → confirmation → login → reset → éditeur (webcam + upload) →
galerie → like → commentaire → notifications → suppression d'image → suppression de
compte. Résultat : rien à corriger, toutes les entrées de `COMPATIBILITY.md` restent
`fixed`. C'est la preuve finale que la stratégie défensive (ES5, XHR, flexbox) a tenu
partout.

## 7 — La validation finale

- **Clone frais** (dans `/tmp, avec ses propres volumes pour ne pas toucher aux
  données de l'utilisateur) + `.env` fait main + `docker compose up --build` : tout
  monte ; les 5 tables se créent seules depuis une base vide ; toutes les pages
  répondent 200 ; l'état « galerie vide » (jamais vu sur le site en marche) s'affiche
  correctement ; seules les lignes de premier démarrage acceptées apparaissent.
- **Spec section 13 cochée ligne par ligne**, `git status`/historique vérifiés.
- **Répétition d'évaluation par l'utilisateur** avec deux comptes, y compris l'option
  « me notifier quand je commente mes propres images ». Sans incident. La partie
  obligatoire est déclarée finie à ce moment-là, et pas avant.

## 8 — `SmtpMailer` : la phase sautée

L'idée de la phase 8 : écrire un **client SMTP en PHP pur** (`stream_socket_client`,
TLS, `AUTH LOGIN`...) pour se passer de msmtp. Décision utilisateur : **sauté** —
msmtp fait déjà le travail simplement et proprement. Conséquence pratique : le driver
de mail est unique, les fichiers `Mailer.php`/`MailerFactory.php` prévus pour choisir
entre plusieurs drivers ont été retirés, et `MAIL_DRIVER` n'existe pas dans le
`.env`.

---

# Phase 9 — Les bonus

**Objectif de la phase.** Quatre bonus choisis par l'utilisateur : **AJAX** sur les
likes/commentaires, **aperçu de l'overlay en direct** sur la webcam, **défilement
infini** de la galerie, **partage social**. (Le GIF animé a été sauté : GD ne sait
pas encoder de GIF animés.)

**La philosophie qui gouverne tout : « progressive enhancement ».** Chaque bonus est
une **couche optionnelle** : le site marche intégralement sans JavaScript, comme
avant. Si le JS tourne, il améliore l'expérience (pas de rechargement de page) ; s'il
échoue, les formulaires et liens d'origine restent. Trois règles de design prises au
départ :

1. **Tout le HTML affiché par le JS est rendu par le serveur** (via des *partials*) :
   l'échappement `e()` reste côté serveur, le JS ne fabrique jamais de HTML à partir
   de données.
2. **Les modes XHR partagent exactement les mêmes contrôles** (auth, CSRF, 404) que
   les modes classiques — seules les réponses diffèrent (JSON au lieu de
   redirection).
3. **Tout le JS est de l'ES5 + XMLHttpRequest** (pas de fetch — entrée 5 du
   COMPATIBILITY.md réutilisée partout).

## 9.1 Les partials (`src/Views/partials/`)

Trois petits templates qui rendent **un fragment** de page :

- `gallery_item.php` : une carte `<li>` de la grille (image, auteur, compteurs) ;
- `gallery_items.php` : la boucle qui rend toutes les cartes d'une page — utilisé par
  la page galerie **et** par la réponse JSON du défilement infini ;
- `comment.php` : un commentaire `<li>` (auteur, date, corps) — utilisé par la page de
  détail **et** par la réponse JSON du POST de commentaire.

C'est la clé du point 1 : la page initiale et le contenu injecté plus tard par le JS
proviennent du **même fichier**, donc sont identiques au octet près — et
l'échappement n'existe qu'à un seul endroit.

## 9.2 Les branches XHR côté serveur

Le signal est partout le même : l'en-tête `X-Requested-With: XMLHttpRequest` (posé
par nos scripts). Trois contrôleurs l'utilisent :

- **`LikeController`** : rejets en JSON `401` (non connecté) et `403` (CSRF), succès
  en JSON `{liked, count}`. Le chemin « classique » (flash + redirection) est
  inchangé.
- **`CommentController`** : validation → `422` JSON avec le message d'erreur ;
  succès → `{html: <partial rendu>, count: N}`.
- **`GalleryController::index`** : une requête XHR sur `/?page=N` renvoie
  `{page, totalPages, hasMore, html: <cartes rendues>}`.

Note la symétrie des codes : **401** = pas connecté, **403** = jeton CSRF invalide,
**422** = contenu invalide (validation). Côté JS, chaque cas a son affichage.

## 9.3 `public/assets/js/image.js` — like et commentaire sans rechargement

Deux blocs (IIFE ES5, même squelette qu'editor.js) :

- **Like** : intercepte le `submit` du formulaire (`event.preventDefault()` — le
  navigateur ne suit plus l'action du formulaire), `postJson` vers l'action du
  formulaire avec le jeton en champ **et** en en-tête, puis met à jour le libellé
  du bouton (Like/Unlike), sa classe (état « on ») et le compteur, tout en place.
  Un clic pendant un envoi en cours est ignoré (drapeau `likePending`). En cas
  d'échec : le message du serveur dans la ligne de statut — et le formulaire classique
  reste disponible.
- **Commentaire** : sérialise le corps avec `encodeURIComponent`, POST en JSON ;
  la réponse contient le `<li>` **déjà rendu et échappé** par le serveur, inséré avec
  `insertAdjacentHTML('beforeend', ...)` (le HTML vient du serveur de confiance, pas
  d'une donnée brute) ; le compteur et le champ sont mis à jour. Détail délicat
  géré : le premier commentaire doit **créer** la liste `<ul>` et remplacer le
  paragraphe « No comments yet ».

## 9.4 `public/assets/js/gallery.js` — le défilement infini

- Lit `data-page`/`data-total-pages` posés par la vue sur la grille (passe de
  données serveur → JS par attributs, jamais par inline `<script>`).
- `nearBottom()` : vrai quand le viewport approche à moins de 700 px du bas →
  `loadNextPage()` : XHR **GET** sur `/?page=N+1`, réponse JSON, cartes ajoutées à la
  grille, `page` mis à jour.
- **La nav de pagination ne disparaît jamais vraiment** : elle est cachée
  (`.is-hidden`) quand le JS fonctionne, et **restaurée si un chargement échoue** —
  le repli sans JS n'est jamais perdu.
- Deux cas limites gérés : une page courte qui ne déclencherait aucun `scroll`
  (rechargement en chaîne tant que le viewport n'est pas plein), et une galerie à
  une seule page (le script s'arrête immédiatement, les liens restent).

## 9.5 L'aperçu d'overlay en direct (`editor.js`, complément)

Le canvas `#editor-overlay-canvas` est superposé à la vidéo (positionné par le CSS
section « Bonuses (phase 9) »). Quand un overlay est coché :

- `selectOverlayPreview()` charge le PNG depuis l'attribut `data-overlay-src` du
  radio (rendu par le serveur — même liste blanche que la capture) ;
- `startOverlayPreview()` démarre une boucle `setInterval` à 100 ms (10 fps) dès que
  webcam **et** overlay sont prêts (le premier des deux qui arrive lance la boucle) ;
- `drawOverlayPreview()` dessine le frame vidéo puis l'overlay **avec la même
  géométrie que le serveur** (fit + centré) — le client imite `ImageComposer`, mais
  **le serveur produit seul l'image finale** enregistrée : l'aperçu n'est qu'une
  vitrine.

Le `setInterval` (et non `requestAnimationFrame`) reste dans le sous-ensemble
d'APIs compatibles. Version du script montée à `?v=4` dans la vue.

## 9.6 Le partage social (`GalleryController` + `image.php`)

Sans bibliothèque ni clé d'API : les trois réseaux (X/Twitter, Facebook, Reddit)
exposent des **URL de partage** publiques du type
`https://twitter.com/intent/tweet?url=<...>&text=<...>`. Le contrôleur les construit
(`shareLinks()`, avec `rawurlencode`), la vue les affiche en liens
`target="_blank" rel="noreferrer noopener"`.

Pour que l'aperçu de l'image existe quand on colle le lien, la page de détail porte
des **balises Open Graph** (`og:title`, `og:url`, `og:image`), construites par
`ogMeta()` — échappées et insérées dans le `<head>` par la ligne
`$headMeta` ajoutée à `layout/app.php`. C'est le seul point où un contrôleur
fabrique du HTML « meta » ; tout le reste reste dans les vues.

## 9.7 Vérification des bonus

Chaque bonus a été vérifié côté serveur (curl sur tous les modes XHR : pages JSON,
like on/off, commentaire échappé, rejets 401/403/422, non-régression des chemins
classiques), puis par l'utilisateur en VM dans les deux navigateurs — aucun
problème de compatibilité (le motif ES5/XHR a tenu ; aucune nouvelle entrée dans
COMPATIBILITY.md). Le projet est ensuite déclaré **fini** dans `PLAN.md` : partie
obligatoire validée de bout en bout + quatre bonus.

---

# Annexes

## A. Lancer et administrer le projet

```bash
docker compose up --build   # construit (si besoin) et démarre tout
docker compose logs         # voir la sortie des conteneurs (attendu : rien
                            # en utilisation, seulement les lignes de
                            # démarrage listées dans NOTES.md)
docker compose down         # arrêter (jamais -v sans réfléchir : ça efface
                            # les volumes, donc les données)
```

- Prérequis unique : le fichier `.env` à la racine, **créé à la main** (il n'existe
  pas de modèle, et il n'est pas commité). Le site est servi sur
  `http://localhost`.
- La base se prépare toute seule à chaque démarrage (`bin/setup-db.php` via
  l'entrypoint) : rien à faire pour un clone neuf, et rien n'est perdu sur une base
  existante.
- Pour tester la webcam depuis une VM : faire en sorte que la VM voie le site comme
  `http://localhost` (redirection de port SSH — l'astuce est dans le README), sinon
  Chrome refuse la caméra (origine non sécurisée).

## B. Les variables d'environnement (`.env`)

| Variable | Rôle |
|---|---|
| `APP_URL` | URL publique de base (liens des emails) — son hôte est toujours autorisé |
| `APP_ALLOWED_HOSTS` | (hors spec, ajoutée avec accord) motifs d'hôtes supplémentaires autorisés dans les liens d'emails |
| `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` | base + utilisateur créés par l'image Postgres au premier départ |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | connexion PHP (`DB_HOST=db`, le nom du service compose ; les valeurs doivent correspondre aux `POSTGRES_*`) |
| `MAIL_FROM` / `MAIL_FROM_NAME` | adresse et nom de l'expéditeur |
| `SMTP_HOST` / `SMTP_PORT` | serveur SMTP de relais |
| `SMTP_SECURE` | `ssl` (465) ou `starttls` (587) |
| `SMTP_USER` / `SMTP_PASS` | identifiants du relais |

Deux variables optionnelles hors `.env` : `APP_LOG_FILE` et `APP_UPLOAD_DIR`
(remplacent les valeurs par défaut, utiles hors docker — documentées dans
NOTES.md).

## C. Arborescence annotée

```
camagru/
├── docker-compose.yml        # les 3 services + les 3 volumes (voir 1.2)
├── .env                      # secrets — jamais commité, créé à la main
├── .gitignore                # .env, uploads/, logs
├── AGENTS.md                 # règles de travail de l'agent
├── PROJECT_CONTEXT.md        # la spec (source de vérité du projet)
├── PLAN.md                   # les phases, les cases, les notes de décision
├── NOTES.md                  # outils justifiés, leviers de silence console
├── COMPATIBILITY.md          # le journal Firefox 41 / Chrome 46
├── db/schema.sql             # les 5 tables, idempotent
├── bin/setup-db.php          # attend la base, crée ce qui manque
├── config/overlays.php       # id => fichier PNG (liste blanche)
├── docker/
│   ├── nginx/default.conf    # front controller, en-têtes, /uploads/ sans PHP
│   └── php/{Dockerfile, php.ini, camagru-fpm.conf, entrypoint.sh}
├── public/                   # SEULE racine web de nginx
│   ├── index.php             # le front controller
│   ├── favicon.png
│   └── assets/{css/app.css, js/{editor,image,gallery}.js, overlays/*.png}
└── src/
    ├── bootstrap.php         # autoload, erreurs, session
    ├── helpers.php           # e(), e_attr()
    ├── routes.php            # la table des routes
    ├── Core/                 # Env, Database, Request, Response, Router, View,
    │                         # Session, Csrf, Validator, Auth, SiteUrl,
    │                         # NotFoundException
    ├── Controllers/          # Auth, Account, Editor, Image, Gallery, Like,
    │                         # Comment (tous fins : les modèles tiennent le SQL)
    ├── Models/               # User, PasswordReset, Image, Like, Comment
    ├── Services/
    │   ├── ImageException.php
    │   ├── ImageComposer.php # LE pipeline d'images
    │   └── Mail/             # MessageBuilder, MsmtpMailer, AppMailer
    └── Views/
        ├── layout/app.php    # header/nav/flash/main/footer
        ├── pages/            # register, login, forgot/reset password, account,
        │                     # editor, gallery, image, 404, 500
        ├── partials/         # comment, gallery_item, gallery_items (phase 9)
        └── emails/           # verification, reset_password,
                              # comment_notification (+ jumeaux _text)
```

## D. Glossaire rapide des fonctions clés rencontrées

| Fonction | Rôle en une ligne |
|---|---|
| `htmlspecialchars` | échappe `<>&"'` — cœur de la défense XSS (`e()`) |
| `password_hash` / `password_verify` | hacher / vérifier un mot de passe (bcrypt) |
| `random_bytes` + `bin2hex` | générer un secret aléatoire (jetons, noms de fichiers) |
| `hash_equals` | comparaison en temps constant (jetons CSRF et reset) |
| `hash('sha256', ...)` | empreinte d'un jeton, stockée au lieu du jeton |
| `session_regenerate_id(true)` | rotation d'identifiant de session (anti-fixation) |
| `spl_autoload_register` | charge les classes à la demande (l'autoloader) |
| `ob_start` / `ob_get_clean` | capture la sortie : un template devient une chaîne |
| `finfo` / `getimagesize` | type réel / dimensions d'une image (contenu, pas nom) |
| `imagecreatefromstring`, `imagecopyresampled`, `imagepng` | décoder, composer, encoder en GD |
| `mail` | délègue l'envoi au programme `sendmail_path` (msmtp ici) |
| `PDO::prepare` / `execute` | requête préparée + données liées (anti-injection SQL) |
| `getUserMedia` | accès à la webcam (via le wrapper compat) |
| `toDataURL` / `atob` / `Blob` | exporter un canvas en PNG binaire sans `toBlob` |
| `XMLHttpRequest` | requête HTTP en JS — utilisée partout au lieu de `fetch` |

---

## Comment réviser le projet avec ce document

1. Lis la Partie 0 en entier, puis la Phase 1 en ouvrant chaque fichier cité
   (`docker-compose.yml`, `Dockerfile`, `default.conf`, `schema.sql`,
   `bootstrap.php`, puis chaque classe de `src/Core`).
2. Pour la phase 2, suis le trajet d'un email de bout en bout : `AuthController::
   register` → `AppMailer` → `MessageBuilder` → `MsmtpMailer` → `php.ini`
   (`sendmail_path`) → `entrypoint.sh` (`msmtp`).
3. Pour la phase 3, ouvre `editor.js` et `ImageComposer.php` **côte à côte** : le
   client produit un PNG, le serveur le re-valide et le re-encode entièrement — tout
   ce que fait le client n'est que du confort.
4. Pour la phase 4, suis un `POST /images/{id}/comments` de bout en bout (route →
   gardes → validation → modèle → notification → vue).
5. Pour la phase 9, compare `partials/comment.php` avec le code de `image.js` :
   le même fragment sert la page initiale et l'insertion AJAX.

Document rédigé d'après l'état final du dépôt (commit « phase 9 : bonus »). Toute
divergence avec un fichier doit être considérée comme le fichier qui a raison.
