# Camagru — Introduction : PHP et les mécanismes du projet, expliqués à un débutant

Ce document est le **premier à lire**. Il ne suit pas le code fichier par fichier
(c'est le rôle d'`explication.md`, qui vient juste après) : il te donne les bases
du langage PHP, puis décortique les mécanismes **non triviaux** que le projet
utilise — ceux qui déroutent quand on n'a jamais écrit de PHP sans framework.

Il est écrit pour quelqu'un qui ne connaît (presque) rien au PHP. Chaque
mécanisme est expliqué avec trois questions dans cet ordre :

1. **à quoi ça sert** (le problème avant la solution) ;
2. **comment ça marche**, pas à pas, sur un extrait réel du projet ;
3. **où le retrouver dans le code** (`chemin/fichier.php`).

Le fil rouge à garder en tête pendant toute la lecture :

> Camagru n'a **pas le droit** d'utiliser un framework, Composer, un moteur de
> template ou un ORM. Tout ce qu'un framework fait « automatiquement »
> (routage, templates, accès base de données, validation, CSRF, envoi de mail…)
> est ici **écrit à la main**. C'est ce qui rend le projet formateur — et c'est
> ce qui fait que certains fichiers paraissent étranges au premier regard.

Sommaire :

- **Partie A** — PHP pour partir de zéro : le langage, les types, les tableaux,
  les classes, les exceptions.
- **Partie B** — HTTP en 10 minutes : requête, réponse, GET/POST, sessions.
- **Partie C** — Les mécanismes non triviaux du projet, décortiqués
  (autoloader, moteur de template maison, routeur à regex, gestion d'erreurs
  en trois couches, état statique, etc.).
- **Partie D** — Sécurité : pourquoi chaque mécanisme existe.
- **Partie E** — Vivre sans framework : la table de correspondance.
- **Partie F** — Où continuer.

---

# Partie A — PHP pour partir de zéro

## A.1 Ce qu'est PHP, en une page

PHP est un langage exécuté **côté serveur**. Le navigateur ne voit jamais une
seule ligne de PHP : il n'envoie que des requêtes HTTP et ne reçoit que du
HTML (ou du JSON, du CSS, des images…). Le code PHP **génère** la réponse.

```php
<?php
echo "<h1>Bonjour</h1>";   // echo envoie du texte vers la réponse HTTP
```

Un fichier `.php` mélange librement HTML et PHP : tout ce qui est hors des
balises `<?php ... ?>` est envoyé tel quel. Dans Camagru, les vues
(`src/Views/`) utilisent cette faculté ; tout le reste du code est du PHP pur
sans HTML.

Deux règles d'or de PHP que le projet respecte partout et qu'il faut
comprendre **avant** de lire le code :

1. **PHP ne garde rien en mémoire entre deux requêtes.** À la fin d'une
   requête, tout le programme disparaît : variables, objets, connexions à la
   base de données. C'est le modèle « share-nothing ». La seule façon de
   reconnaître un visiteur d'une requête à l'autre, c'est la **session**
   (partie B.4).
2. **Chaque requête repasse par le front controller.** Dans ce projet, *toute*
   requête arrive dans `public/index.php` (nginx y envoie tout ce qui n'est
   pas un fichier statique). C'est le point d'entrée unique ; il charge
   `src/bootstrap.php`, trouve la route, appelle un contrôleur, envoie la
   réponse. Ce n'est pas PHP qui impose ça, c'est le projet : ça s'appelle un
   « front controller pattern » et ça imite ce que font les frameworks.

## A.2 Variables, types, casts

Une variable commence toujours par `$`. Pas de déclaration de type à
l'avance : une variable vaut ce qu'on y met.

```php
$name = 'alice';        // chaîne de caractères
$count = 6;             // entier
$ratio = 0.5;           // nombre décimal
$ok = true;             // booléen
```

PHP est *faiblement typé* : `'5'` (chaîne) et `5` (entier) se comparent égaux
avec `==`. C'est une source classique de bugs. La parade, utilisée partout
dans ce projet, est la comparaison **stricte** `===` (valeur **et** type) :

```php
0 == ''    // true  (!) piège classique de PHP
0 === ''   // false — c'est cette version qu'on utilise
```

La plupart des fonctions PHP « signalent un problème » en retournant `false`
au lieu de lever une erreur. D'où des tests omniprésents du type :

```php
$user = User::findByUsername($name);
if ($user === null) { ... }   // null : « rien trouvé »
```

### `declare(strict_types=1)` : la première ligne de chaque fichier

Chaque fichier PHP du projet commence par :

```php
declare(strict_types=1);
```

Sans cette ligne, PHP convertit silencieusement les types : appeler
`maFonction('5')` quand la fonction attend un `int` fonctionne quand même
(`'5'` devient `5`). Avec, PHP **refuse** : lever des types ambiguïtés au
moment de l'appel vaut mieux qu'une conversion invisible qui masque un bug.

C'est pour ça que tu verras aussi beaucoup de **casts explicites** :
`(int) $id`, `(string) $value`. Ils disent au lecteur « ici, on sait que la
valeur peut arriver sous une autre forme, on la convertit volontairement,
au bon endroit, plutôt que de laisser PHP deviner ».

## A.3 Chaînes : quotes et concaténation

```php
$a = 'texte';            // quotes simples : rien n'est interprété
$b = "Bonjour $name";    // quotes doubles : $name est remplacé (interpolation)
$c = 'Bonjour ' . $name;  // le point concatène
```

Le projet utilise le plus souvent la concaténation avec `.` et `sprintf`
(`sprintf('... %s ...', $var)` construit une chaîne à partir d'un « patron »)
— deux habitudes lisibles, sans surprise d'interpolation.

## A.4 Le tableau associatif : LA structure de données du projet

C'est de loin la structure la plus importante à comprendre. Un tableau PHP
associe des **clés** à des **valeurs** ; les clés peuvent être des nombres ou
des chaînes.

```php
$user = [
    'id'        => 42,
    'username'  => 'alice',
    'email'     => 'alice@example.com',   // exemple, aucune donnée réelle
];

$user['username'];   // lire : 'alice'
$user['id'];         // 42
isset($user['bio']);  // false : la clé n'existe pas
```

Pourquoi c'est central ici :

- **une ligne de base de données** lue via PDO arrive exactement sous cette
  forme (voir `src/Models/User.php`) ;
- **la requête HTTP** est représentée ainsi : `$request->query('page')`,
  `$request->post('body')` lisent des tableaux `$_GET` / `$_POST` ;
- **la session** est un tableau : `$_SESSION['user_id'] = 42;` ;
- **les données passées aux vues** sont un tableau : `['title' => 'Login',
  'errors' => [...]]` — et c'est un mécanisme spécial (`extract()`,
  partie C.2) qui les transforme en variables dans les templates.

Boucles et fonctions utiles sur les tableaux :

```php
foreach ($images as $image) { ... }          // parcourir les valeurs
foreach ($users as $id => $name) { ... }     // parcourir clé + valeur
count($images);                              // nombre d'éléments
array_merge($a, $b);                         // fusionner (écrase les clés communes)
$filtered = array_filter($arr, $callback);   // garder les éléments qui passent le test
```

L'opérateur **null coalescing** `??`, très utilisé dans le projet :

```php
$page = $_GET['page'] ?? 1;
// équivaut à : "si $_GET['page'] existe et n'est pas null, prendre sa valeur, sinon 1"
// sans ?? il faudrait isset() + test, et sans précaution un accès direct
// $_GET['page'] sur une clé absente déclenche un warning.
```

## A.5 Fonctions et typage

```php
/**
 * @param string $name ...
 * @return string ...
 */
function greet(string $name): string   // types en entrée ET en sortie
{
    return 'Bonjour ' . $name;
}
```

Depuis PHP 7/8, on peut (et le projet le fait systématiquement) déclarer les
types des paramètres et de la valeur de retour. Avec `strict_types` (A.2), ces
annotations sont **vérifiées** : elles ne sont pas décoratives.

## A.6 Fonctions anonymes (closures)

PHP permet de créer une fonction « sur place » et de la passer comme une
valeur — exactement comme `function` en JavaScript :

```php
// src/Controllers/GalleryController.php:86 — formater chaque commentaire
$comments = array_map(function (array $comment): array {
    $comment['date'] = self::formatDate((string) $comment['created_at']);
    return $comment;
}, $comments);
```

`array_map($fonction, $tableau)` applique `$fonction` à chaque élément et
renvoie le tableau transformé. On retrouve des fonctions anonymes à quatre
endroits clés du projet : l'autoloader, les trois gestionnaires d'erreurs
(`src/bootstrap.php`), ce `array_map`, et `preg_replace_callback` dans le
routeur (partie C.3).

## A.7 Classes : propriétés, méthodes, `new`

```php
class Validator
{
    /** @var array<string, string> Field name => error message. */
    private array $errors = [];    // propriété : variable attachée à l'objet

    public function email(string $field, string $value): self   // méthode
    {
        ...
        return $this;              // $this = l'objet courant
    }
}

$validator = new Validator();      // créer une instance
$validator->email('email', $v);    // -> : appeler une méthode sur l'objet
```

Vocabulaire :
- **propriété** : variable vivant dans un objet ; `$this->errors` y accède.
- **méthode** : fonction d'un objet.
- **visibilité** (`public` / `private`) : `private` = interdit depuis l'extérieur
  de la classe. C'est une protection contre les usages accidentels : si une
  propriété est `private`, personne ne peut la modifier sans passer par les
  méthodes prévues pour.
- `new ClassName()` fabrique un objet.

Le projet suit une règle simple : **une classe par fichier**, le fichier
portant le nom de la classe (`Validator.php`).

### Méthodes et propriétés `static` : sans instance

Normalement, une méthode agit sur un objet créé avec `new`. Une méthode
`static` se rattache à la **classe elle-même** et s'appelle avec `::` :

```php
User::findById(42);        // pas de "new User" avant
Database::connection();    // idem
```

La plupart du code du projet est écrit ainsi : `Csrf::token()`,
`Session::get('x')`, `Image::page(6, 0)`… Le `::` se lit « la classe fournit
ce service ». À l'intérieur d'une classe, `self::` désigne la classe courante
(`self::PER_PAGE` dans `GalleryController`) et `self::method()` une autre
méthode de la même classe.

**Quand est-ce qu'on utilise `new` alors ?** Une seule fois par requête, dans
`public/index.php:39` : le contrôleur. C'est le seul objet « vivant » du
projet ; tout le reste sont des regroupements de fonctions statiques, un
choix raisonnable pour un projet de cette taille (voir C.5 pour le revers de
la médaille : l'état `static`).

### `::class` : le nom d'une classe comme valeur

```php
$router->add('GET', '/', GalleryController::class, 'index');
```

`GalleryController::class` est remplacé, à la lecture du fichier, par la
**chaîne** `'App\Controllers\GalleryController'`. C'est le nom de la classe,
sous forme de texte, sans instancier quoi que ce soit. `routes.php` construit
ainsi une table « chemin → (nom de classe, nom de méthode) », et
`index.php` instanciera la classe plus tard (partie C.6).

## A.8 Namespaces et `use`

Un namespace est un « nom de famille » pour les classes, pour éviter les
collisions et rendre les noms explicites :

```php
// src/Models/User.php
namespace App\Models;

class User { ... }     // nom complet : App\Models\User
```

En haut de chaque fichier, les `use` déclarent « dans ce fichier, `User`
signe `App\Models\User` » :

```php
// src/Controllers/AuthController.php
use App\Core\Auth;
use App\Models\User;
// maintenant "User::findById(1)" désigne App\Models\User
```

`namespace` et `autoload` sont deux choses **indépendantes** : le namespace
range les noms, l'autoloader (partie C.1) charge les fichiers. Le projet a
juste fait le choix standard de les faire correspondre :
`App\Core\Csrf` ↔ fichier `src/Core/Csrf.php`.

## A.9 Exceptions : `throw`, `try` / `catch`

Une exception est un signal d'erreur qui **remonte** la pile d'appels jusqu'à
ce que quelqu'un l'attrape :

```php
try {
    $image = Image::findWithAuthor($id);   // peut lancer NotFoundException
} catch (NotFoundException $e) {
    // ici on décide quoi en faire : page 404
}
```

- `throw new X(...)` : lancer. L'exécution de la fonction s'arrête net.
- `catch (X $e)` : attraper, à condition que l'exception soit du type `X`
  (ou d'une classe enfant).
- `Throwable` : le type racine de **toutes** les erreurs de PHP 8, exceptions
  « classiques » comme `RuntimeException`, mais aussi les erreurs fatales
  (`TypeError`…). Le projet attrape `Throwable` dans `public/index.php` pour
  que rien ne puisse produire une page d'erreur PHP brute.

Règle du projet : une exception non attrapée est attrapée **au sommet**, par
le gestionnaire installé dans `bootstrap.php` (partie C.4), qui logge et
affiche une page 500 générique.

## A.10 Superglobales : `$_GET`, `$_POST`, `$_SESSION`, `$_FILES`

Ce sont des tableaux remplis par PHP avant le début de ton code :

| Variable | Contenu |
|---|---|
| `$_GET` | paramètres d'URL (`?page=3` → `['page' => '3']`) |
| `$_POST` | champs d'un formulaire envoyé en POST |
| `$_SESSION` | données attachées au visiteur courant (partie B.4) |
| `$_FILES` | fichiers uploadés |
| `$_SERVER` | infos de la requête (méthode, URI, en-têtes) |

**Deux pièges, deux règles du projet :**

1. Le contenu vient de l'extérieur → il est **non fiable** par définition
   (jamais échapper oublié, jamais SQL direct, partie D).
2. Le projet **interdit presque tout accès direct** : `Request`
   (`src/Core/Request.php`) enveloppe `$_GET`/`$_POST`/`$_SERVER`, `Session`
   enveloppe `$_SESSION`, et seuls `ImageComposer`/contrôleurs touchent
   `$_FILES`. Avantage : un seul endroit à auditer si un jour une valeur
   doit être validée autrement.

## A.11 L'opérateur `@`

Placé devant une expression, `@` **demande à PHP de se taire** : si la
fonction génère un warning, il n'est ni affiché ni loggé — le retour `false`
reste testable.

```php
// src/Services/ImageComposer.php:160
$image = @imagecreatefromstring($bytes);
// puis : if ($image === false) { throw new ImageException(...); }
```

La règle apprise partout est « le `@` est mal » — et c'est vrai quand il
sert à **cacher** un problème. Ici il a un but différent et précis : la
consigne « zéro sortie console » impose qu'un fichier corrompu envoyé par un
utilisateur ne génère **jamais** un warning GD dans les logs. Le code fait
donc l'inverse du cache-erreur : il vérifie le retour et **transforme** le
cas en `ImageException` (avec message propre) via le pipeline d'erreurs de C.4.
La différence entre les deux usages :
- cacher : `@` puis on continue comme si de rien n'était ;
- ici : `@` + test du retour + erreur applicative explicite.

---

# Partie B — HTTP en dix minutes

*(Résumé opérationnel — `explication.md` partie 0 raconte le trajet complet
d'une requête dans Camagru, avec schéma.)*

## B.1 Requête et réponse

Une requête HTTP, c'est : une **méthode**, un **chemin**, éventuellement des
en-têtes et un corps. Une réponse, c'est : un **code de statut**, des
en-têtes, un corps (HTML, JSON…).

Codes utilisés par le projet :

| Code | Sens | Où |
|---|---|---|
| 200 | OK | toutes les pages normales |
| 302 | redirection | login obligatoire, après POST réussi |
| 404 | introuvable | route inconnue, image inconnue |
| 500 | erreur serveur | exception non attrapée (page générique) |

## B.2 GET et POST

- **GET** : lire. Les paramètres voyagent dans l'URL (`/images/42`, `?page=2`).
- **POST** : modifier (créer un compte, liker, commenter, supprimer…). Les
  données voyagent dans le corps de la requête.

Règle du projet : **toute action qui change quelque chose est un POST**
(routes dans `src/routes.php`). Un GET doit toujours être « sans effet de
bord » : c'est ce qui empêche qu'un simple lien (ou une image piégée sur un
autre site) puisse supprimer un compte.

## B.3 Cookies

Le serveur peut demander au navigateur de **ranger une petite valeur** et de
la renvoyer à chaque requête suivante. Un cookie en soi ne stocke rien de
« connecté » : il ne fait qu'identifier. C'est le mécanisme sous la session.

## B.4 Session : comment PHP se « souvient » du visiteur

Contrainte de A.1 : à la fin de chaque requête, tout disparaît. La session
est la solution :

1. À la première visite, PHP fabrique un identifiant aléatoire (ex.
   `camagru_sid=abc123...`), l'envoie comme cookie, et crée un fichier
   (vide) côté serveur nommé d'après cet identifiant.
2. Aux requêtes suivantes, le navigateur renvoie le cookie ; PHP ouvre le
   fichier correspondant et le rend disponible dans `$_SESSION`.
3. Le projet y range une seule chose pour la connexion :
   `$_SESSION['user_id'] = 42` (voir `src/Core/Auth.php`). « Être connecté »
   signifie juste : la session contient un id d'utilisateur vérifié.

Réglages de sécurité appliqués dans `src/bootstrap.php:107-118` :
`httponly` (le cookie est invisible pour le JavaScript de la page — un XSS ne
peut pas le voler), `samesite=Lax` (le navigateur ne l'envoie pas depuis un
autre site — aide contre le CSRF, D.8), `use_strict_mode=1` (refuser un
identifiant de session inventé par le client).

### Messages flash : un état sur DEUX requêtes

Besoins récurrent : « affiche "compte créé" sur la page suivante ». Impossible
avec une simple variable (elle disparaît, A.1). La parade : ranger le message
**dans la session**, l'afficher à la requête suivante, puis **l'effacer** —

```php
// src/Core/Session.php
Session::flash('success', 'Account created.');   // requête N : on le range
$flashes = Session::pullFlashes();               // requête N+1 : on le lit ET
                                                 // on l'efface en même temps
```

C'est le motif « écrire-une-fois, lire-une-fois » ; « pull » signifie que la
valeur est consommée. Sans l'effacement, le message reviendrait à chaque page.

---

# Partie C — Les mécanismes non triviaux du projet, décortiqués

C.1 à C.11 reprennent le code réel, fichier par fichier. Prends le temps de
rouvrir chaque fichier cité à côté de sa section.

## C.1 L'autoloader (`src/bootstrap.php:7-20`)

**Problème.** 30 classes, 84 `use` répartis dans les fichiers. Sans
mécanisme, chaque fichier aurait besoin de `require` à la main les fichiers
des classes qu'il utilise — et leurs propres dépendances.

**Solution : charger à la demande.** PHP appelle l'autoloader à chaque fois
qu'une classe est utilisée mais pas encore chargée :

```php
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;                     // classe hors du projet : on ignore
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
```

Pas à pas pour `App\Models\User` :

1. `substr` retire le préfixe `App\` → `Models\User` ;
2. `str_replace` convertit les `\` en `/` → `Models/User` ;
3. `__DIR__` (le dossier du fichier bootstrap, c'est-à-dire `src/`) donne le
   chemin complet → `src/Models/User.php` ;
4. s'il existe, `require` le charge.

C'est tout. La convention « namespace = arborescence » (`App\Models\User`
↔ `src/Models/User.php`) s'appelle PSR-4 ; c'est **exactement** ce que
Composer génère automatiquement — ici écrit à la main en 12 lignes de
bibliothèque standard, sans dépendance.

**Bénéfice double** : zéro `require` à maintenir, et une requête ne charge
que les ~8-10 classes qu'elle touche réellement (les 30 fichiers ne sont
jamais tous analysés).

## C.2 Le moteur de template maison : `extract()` + `ob_start()` (`src/Core/View.php`)

**Problème.** Les vues contiennent du HTML et doivent recevoir des données
(titre, liste d'images, utilisateur courant…). Un framework utilise Blade ou
Twig ; ici il faut le faire à la main. C'est le mécanisme le plus « magique »
du projet — décortiquons `renderPartial()` :

```php
// src/Core/View.php:65-80
public static function renderPartial(string $template, array $data = []): string
{
    $file = __DIR__ . '/../Views/' . $template;
    if (!is_file($file)) {
        throw new \RuntimeException("View not found: $template");
    }
    $data = array_merge(self::$shared, $data);
    extract($data, EXTR_SKIP);       // (1)
    ob_start();                      // (2)
    require $file;                   // (3)
    return (string) ob_get_clean();  // (4)
}
```

Appel typique : `View::render('pages/gallery.php', ['title' => 'Gallery',
'images' => $images])`.

1. **`extract($data, EXTR_SKIP)`** transforme chaque clé du tableau en
   variable locale : après cet appel, la fonction possède `$title` et
   `$images` comme si elles avaient été déclarées. C'est ce qui explique
   qu'une vue utilise `$images` **sans jamais le recevoir en paramètre** :
   la variable est injectée dans le scope par `extract`.
   `EXTR_SKIP` : si une variable du même nom existe déjà, **on ne l'écrase
   pas** — garde de sécurité qui empêche `$data` de réécrire une variable
   interne (voir D.11).
2. **`ob_start()`** active le « output buffering » : tout ce que le code
   produit à partir de maintenant est **capturé en mémoire** au lieu d'être
   envoyé au navigateur.
3. **`require $file`** exécute le template. Le template contient du HTML
   avec du PHP : tout son `echo`/HTML part dans le tampon, et il lit
   directement les variables injectées en (1).
4. **`ob_get_clean()`** renvoie le contenu du tampon et l'efface. La fonction
   **retourne donc le HTML sous forme de chaîne**, que l'appelant peut
   transmettre, ranger, redécorer… Le point clé : un `require` ne « montre »
   rien par lui-même ; c'est le tampon qui décide où part la sortie.

`renderPage()` emboîte deux appels : d'abord la page (→ `$content`), puis le
layout `layout/app.php` qui reçoit `$content` et `$flashes` par le même
mécanisme. Un template dans un template, sans moteur de template.

**`View::$shared`** : quelques données (utilisateur courant, token CSRF)
sont nécessaires à *toutes* les pages ; `index.php` les enregistre une fois
avec `View::share([...])`, et `renderPartial` les fusionne dans `$data` à
chaque rendu.

## C.3 Le routeur : des URL vers des contrôleurs (`src/Core/Router.php`)

**Problème.** Associer `/images/42` à « méthode `show` de
`GalleryController` avec `id = 42` ». Sans framework : une table de routes et
une regex.

**Étape 1 — enregistrer la route** (`src/routes.php`) :

```php
$router->add('GET', '/images/{id}', GalleryController::class, 'show');
```

**Étape 2 — compiler `{id}` en regex** (`Router.php:22-29`) :

```php
$compiled = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $m): string {
    return '(?<' . $m[1] . '>[^/]+)';
}, $pattern);
```

- `preg_replace_callback` cherche toutes les occurrences de `{...}` dans le
  pattern et les remplace par ce que la fonction anonyme renvoie ;
- `$m[1]` contient le nom capturé : `id` ;
- elle renvoie `(?<id>[^/]+)` : un **groupe de capture nommé**. Traduction :
  « capture un morceau d'au moins un caractère qui n'est pas un slash, et
  appelle-le `id` ».

Résultat : `/images/{id}` devient `#^/images/(?<id>[^/]+)$#` (`^` début, `$`
fin, `#` délimiteur de regex — on évite `/` qui est déjà dans l'URL).

**Étape 3 — faire matcher** (`Router.php:43-62`) :

```php
if (preg_match($route['compiled'], $path, $matches)) {
    $params = [];
    foreach ($matches as $key => $value) {
        if (is_string($key)) {
            $params[$key] = $value;
        }
    }
    return ['handler' => $route['handler'], 'params' => $params];
}
```

`$matches`, après un match, est un tableau **bâtard** : il contient le texte
complet sous la clé `0`, les groupes numériques, **et** les groupes nommés
sous des clés… nommées (`'id' => '42'`). D'où le filtre `is_string($key)` :
on ne garde que les clés textuelles — c'est-à-dire nos `{param}`. Le
résultat `{id: '42'}` partira vers le contrôleur dans `$route['params']`.

À retenir : **le routeur ne fait que deux choses** — transformer des motifs
en regex une fois pour toutes, puis essayer chaque regex dans l'ordre. Pas de
magie, juste `preg_*`.

## C.4 La gestion d'erreurs en trois couches (`src/bootstrap.php:65-102`)

**Problème.** Trois familles d'erreurs existent en PHP, et aucune ne se gère
de la même façon :

| Famille | Exemple | Qui peut l'attraper normalement ? |
|---|---|---|
| Exceptions | `throw`, `TypeError`, `PDOException` | `try`/`catch` |
| Warnings / notices | fonction de fichier absente, etc. | **personne** : le script continue ! |
| Erreurs fatales | dépassement mémoire, appel sur `null` | `try`/`catch` **dans certains cas seulement** |

Or la consigne « zéro sortie console » interdit qu'un warning fuie, et la
consigne sécurité interdit d'afficher un détail interne à l'utilisateur. Le
projet installe donc trois filets, du plus large au plus fin :

**Couche 1 — `set_exception_handler`** : attrape toute exception qui arrive
en haut de la pile sans `catch`. Il logge une ligne (`app_log_throwable`),
puis : en CLI, `exit(1)` (script de setup : le conteneur ne doit pas croire
que tout va bien) ; en web, page 500 **générique** (« Something went wrong »,
sans chemin de fichier ni requête SQL — aucun détail interne).

**Couche 2 — `set_error_handler`** : les warnings/ notices ne lèvent pas
d'exception par défaut ; le handler les **convertit** :

```php
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
```

Lecture : un warning devient une `ErrorException` — donc attrapable par la
couche 1 avec un seul chemin de traitement. Détail qui déroute souvent :
`$severity` est un **masque de bits** (des puissances de 2 : WARNING=2,
NOTICE=8…), et `&` est l'opérateur **ET bit à bit** — « ce warning
appartient-il aux niveaux que error_reporting demande de signaler ? ».
C'est ce qui permet au `@` (A.11) de rester silencieux : `@` met le niveau à
zéro temporairement, le test échoue, le handler rend la main à PHP.

**Couche 3 — `register_shutdown_function`** : exécuté quand le script
finit — y compris **par mort subite** (erreur fatale). Le handler relit
`error_get_last()` ; s'il trouve une erreur fatale, il logge et, si les
en-têtes ne sont pas déjà partis (`headers_sent()`), force un code 500.
C'est le filet ultime : les couches 1 et 2 ne voient pas tout, lui voit
toute fin de script.

Résumé du flux d'une erreur, quel que soit son type :

```
warning ──(couche 2)──▶ ErrorException ──┐
exception ordinaire ────────────────────┤──▶ couche 1 : log + page 500 générique
erreur fatale non vue ──(couche 3)──────▶ log + code 500
```

## C.5 L'état `static` : des variables qui vivent « pour la classe » (`src/Core/Auth.php`, `Database.php`, `View.php`)

Les méthodes `static` (A.7) ont une contrepartie : les **propriétés**
`static`, qui existent une seule fois **par requête** (pas par objet — il n'y
a d'ailleurs souvent aucun objet du tout).

```php
// src/Core/Auth.php
private static ?array $user = null;    // cache de la ligne utilisateur
private static bool $loaded = false;   // « a-t-on déjà chargé $user ? »

public static function user(): ?array
{
    if (!self::$loaded) {              // premier appel de CETTE requête
        ...                             // une seule requête SQL
        self::$loaded = true;
    }
    return self::$user;                // appels suivants : réponse immédiate
}
```

Deux motifs à reconnaître, présents trois fois chacun dans le projet :

1. **Mémoïsation** (Auth) : `Auth::user()` est appelé plusieurs fois par page
   (layout, contrôleur…) ; sans ce cache, une requête SQL par appel.
2. **Création paresseuse** (Database) : `Database::connection()` ne crée la
   connexion PDO qu'au **premier** appel, puis la rend telle quelle. Une
   page qui ne touche pas la base (page 404, page statique) ne paie aucune
   connexion.

Le point contre-intuitif pour un débutant : ces valeurs **ne survivent pas**
à la requête (modèle share-nothing, A.1). Elles servent juste à ne pas
refaire le même travail plusieurs fois **dans** une requête.

## C.6 L'instanciation dynamique : `new $controllerClass()` (`public/index.php:37-39`)

```php
[$controllerClass, $method] = $route['handler'];   // ['App\Controllers\...', 'show']
$controller = new $controllerClass();              // la classe vient d'une CHAÎNE
$controller->$method($request, $route['params']); // la méthode aussi
```

Deux nouveautés syntaxiques :

- **`[$a, $b] = ...`** : déstructuration — range les deux éléments d'un
  tableau dans deux variables d'un coup.
- **`new $variable`** : le nom de la classe n'est pas écrit littéralement,
  il vient d'une chaîne. PHP résout la chaîne en classe (l'autoloader la
  charge si besoin — C.1) et instancie. Idem pour `->$method` : appel de
  méthode dont le nom est dynamique.

C'est le pont entre la table de routes (C.3) et le code : au lieu d'un
gigantesque `switch` « si path = /login alors... », **les données pilotent
le code**. Le jour où on ajoute une page : une ligne dans `routes.php`, un
contrôleur — `index.php` ne change pas.

## C.7 Le contrôleur "mince" et le modèle "fat" (convention MVC du projet)

- **Controller** (`src/Controllers/`) : lit la requête, appelle le modèle,
  choisit la vue. **Aucune requête SQL ici.**
- **Model** (`src/Models/`) : classe qui centralise **tout le SQL** d'une
  table. `Like.php` est un exemple parfait à lire : quatre petites méthodes
  (`exists`, `add`, `remove`, `countByImage`) + `toggle()` qui les combine.
- **View** (`src/Views/`) : HTML uniquement + `e()` (D.1). Aucune requête,
  aucune décision.

Pourquoi : si le SQL vit à un seul endroit, l'audit sécurité (toutes les
requêtes sont-elles préparées ? D.2) se fait en lisant cinq fichiers.

## C.8 Le validateur « fluent » (`src/Core/Validator.php`)

```php
$validator = (new Validator())
    ->required('username', $username)
    ->username('username', $username)
    ->password('password', $password);
if ($validator->passes()) { ... }
```

Chaque règle **retourne `$this`** — l'objet lui-même — ce qui permet
d'enchaîner les appels (« chaînage de méthodes »). Chaque règle qui échoue
ajoute un message dans `$this->errors[$field]` (propriété privée, A.7) ;
`passes()` regarde juste si le tableau est vide. Rien d'autre.

Détail utile : `Validator` retourne `self` (`: self` dans la signature), qui
signifie « une instance de la classe courante » — même usage que `$this`
mais dans la déclaration de type.

## C.9 Le toggle « race-free » (`src/Models/Like.php`)

À lire, car c'est de la concurrence déguisée en simple code. Deux clics
« j'aime » quasi simultanés pourraient insérer deux likes. La table `likes`
a une clé primaire **composite** `(user_id, image_id)` : la base elle-même
interdit le doublon. L'insertion utilise `ON CONFLICT DO NOTHING` : si le
doublon arrive, la base ignore l'insertion, sans erreur, sans plantage. Le
code ne « gère » pas la course : il rend le doublon **impossible par
construction**, et la méthode `toggle()` reste une simple lecture de
l'état. C'est un exemple typique de « laisser la base de données faire son
travail » plutôt que d'ajouter des vérifications fragiles côté PHP.

## C.10 `sprintf()` : construire des chaînes à patron

```php
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
// src/Core/Database.php:33
```

`%s` est un emplacement pour une chaîne (il existe `%d` pour les entiers) ;
les arguments remplissent les emplacements dans l'ordre. Le projet
l'utilise là où la concaténation avec `.` deviendrait illisible. À ne pas
confondre avec la construction de SQL : **jamais** de `sprintf` avec des
valeurs utilisateur pour une requête — seulement avec des constantes
d'infrastructure (ici, le nom de la base vient des variables
d'environnement, pas de l'utilisateur).

## C.11 PDO et l'option `ATTR_EMULATE_PREPARES = false` (`src/Core/Database.php`)

```php
self::$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,     // erreur SQL = exception
    PDO::ATTR_EMULATE_PREPARES   => false,                     // vraie préparation
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,          // lignes = tableaux A.4
]);
```

L'option la plus mystérieuse mérite son explication : par défaut, PDO
**simule** les requêtes préparées côté PHP (il fabrique du SQL en interne).
Avec `false`, la préparation passe réellement par PostgreSQL : le plan de
la requête et les données sont séparés dès le départ — et un `?` reste un
paramètre, jamais un morceau de SQL. C'est de la défense en profondeur :
même si la désérialisation des paramètres contenait un jour un défaut, la
base de données ne ferait *pas* confiance au SQL reçu autre part. Voir D.2.

---

# Partie D — Sécurité : pourquoi chaque mécanisme existe

Le principe directeur de toute cette partie : **ne jamais faire confiance à
une donnée venue de l'extérieur** (formulaire, URL, fichier uploadé, en-tête,
base de données elle-même — elle peut contenir du texte saisi par quelqu'un
d'autre).

## D.1 XSS et la fonction `e()` (`src/helpers.php`)

**L'attaque.** Un utilisateur poste en commentaire : `salut <script>voleTonCookie()</script>`.
Si affiché tel quel dans la page, *le navigateur exécute ce script* pour
tous les visiteurs.

**La parade.** Toute sortie affichable passe par :

```php
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

`htmlspecialchars` transforme `<` en `&lt;`, `>` en `&gt;`, `"` en `&quot;`,
`'` en `&#039;` et `&` en `&amp;`. Le texte s'affiche **comme du texte** :
`salut &lt;script&gt;...` — le navigateur ne voit plus de balise. `ENT_QUOTES`
couvre les deux sortes de guillemets (utile dans les attributs HTML),
`ENT_SUBSTITUTE` évite les caractères invalides UTF-8.

Règle du projet, sans exception : **toute** donnée dynamique dans une vue
passe par `e()` — noms d'utilisateur, commentaires, messages flash, liens.

## D.2 Injection SQL et requêtes préparées (`src/Models/*.php`)

**L'attaque.** Construire le SQL par concaténation :
`"... WHERE username = '" . $name . "'"`. Si `$name` vaut `' OR '1'='1`
(ou pire), la requête obtenue n'est plus celle prévue.

**La parade.** Le SQL et les données **ne voyagent jamais ensemble** :

```php
$stmt = Database::connection()->prepare(
    'SELECT 1 FROM likes WHERE user_id = ? AND image_id = ?'
);
$stmt->execute([$userId, $imageId]);   // src/Models/Like.php
```

Le serveur compile d'abord la requête avec des trous (`?`), puis y insère
les valeurs — qui, par construction, **ne peuvent pas redéfinir la
requête**. Note : même `LIMIT`/`OFFSET` sont liés comme paramètres partout
dans le projet (certains moteurs ne les acceptent que littéraux, PostgreSQL
les accepte liés).

## D.3 Mots de passe : `password_hash` / `password_verify` / `password_needs_rehash`

```php
$hash = password_hash($password, PASSWORD_DEFAULT);   // stocké en base, JAMAIS le mot de passe
if (password_verify($password, (string) $user['password_hash'])) { ... }
```

- `password_hash` applique **bcrypt** (avec sel aléatoire intégré) : même deux
  comptes avec le même mot de passe donnent des hash différents, et le
  procédé est **irréversible**. La base peut être lue : elle ne révèle pas
  les mots de passe.
- `password_verify` est la seule façon de comparer (un `===` sur les hash ne
  marche pas — le sel varie).
- `PASSWORD_DEFAULT` désigne « le meilleur algo disponible ». Le jour où PHP
  en change, `password_needs_rehash($hash, PASSWORD_DEFAULT)` (`src/Controllers/AuthController.php:219`)
  répond « ce hash est d'une vieille époque » ; on re-hash au moment d'une
  connexion réussie (le mot de passe en clair n'est disponible qu'à cet
  instant) — migration **transparente**, sans opération de maintenance.

## D.4 Empêcher l'énumération des comptes : le « dummy hash »

Au login, si l'utilisateur n'existe pas, répondre « ce compte n'existe pas »
offrirait un inventaire du site. La réponse est donc générique (« identifiants
invalides »). Mais un attaquant peut chronométrer : vérifier un bcrypt
**coûte du temps de calcul** ; un compte inexistant répondrait plus vite —
le temps de réponse redeviendrait une fuite d'information. D'où
(`src/Controllers/AuthController.php`) : un hash bcrypt d'une **chaîne fixe**,
déclaré en constante (`self::DUMMY_HASH`), contre lequel on vérifie quand
l'utilisateur n'existe pas. Les deux chemins font un vrai calcul bcrypt :
même durée, même réponse. Aucun secret là-dedans : le hash d'une chaîne
publique ne protège rien ; il ne sert qu'à **égaliser le temps**.

## D.5 `hash_equals` : la comparaison en temps constant (`src/Core/Csrf.php:39`, `AuthController.php`)

Comparer deux chaînes caractère par caractère s'arrête **au premier
différent** : microscopiquement plus rapide. Mesuré des millions de fois,
ce temps de réponse permettrait de deviner un token caractère par caractère
(même les navigateurs/utilitaires qui le font — attaque dite « timing »).
`hash_equals($a, $b)` compare **en durée constante**, quel que soit le point
de divergence. Le projet l'utilise pour tous les tokens : CSRF,
vérification d'email, reset de mot de passe.

## D.6 Tokens : aléatoires, hashés, à usage unique, expirants

Trois sortes de tokens (CSRF, lien de vérification d'email, lien de reset)
suivent le même schéma, à comprendre ensemble :

```php
$token = bin2hex(random_bytes(32));              // 32 octets crypto-aléatoires → 64 hex
PasswordReset::create($userId, hash('sha256', $token));   // on ne stocke QUE le hash
```

1. **Génération** : `random_bytes` est le générateur cryptographique (pas
   `rand()`, prévisible). `bin2hex` est juste un encodage en caractères
   imprimables.
2. **Stockage du hash seulement** : le lien email contient le token ; la
   base ne contient que `hash('sha256', $token)`. Si la base fuit, les
   tokens restent inutilisables (SHA-256 est à sens unique). Pourquoi hasher
   un token déjà aléatoire ? Parce que la fuite possible n'est pas
   l'aléatoire, c'est la **base**.
3. **Côté serveur, à l'usage** : on re-hash le token reçu et on compare les
   deux hash avec `hash_equals` (D.5).
4. Le reset est **à usage unique** (marqué `used_at`) et **expirant** (1 h,
   colonne `expires_at`) : un lien intercepté ne sert qu'une fois, qu'une
   heure.

## D.7 Fixation de session : `session_regenerate_id(true)` (`src/Core/Session.php:65-71`)

**L'attaque.** Avant que tu te connectes, un attaquant t'envoie un lien
contenant un identifiant de session qu'il connaît ; si le site te
« connecte » *sur cette session-là*, l'attaquant est connecté avec toi.

**La parade.** Au moment du login, `session_regenerate_id(true)` change
l'identifiant (l'ancien devient inutilisable) ; `true` supprime aussi
l'ancien fichier de session. L'identifiant pré-login ne peut plus rien
ouvrir. Combiné au logout (destruction complète de la session), c'est ce
qui rend la session « le seul credential store » (voir `src/Core/Auth.php`
en-tête).

## D.8 CSRF (`src/Core/Csrf.php`)

**L'attaque.** Tu es connecté. Un autre site, ouvert dans un autre onglet,
peut faire soumettre **silencieusement** un formulaire vers
`camagru/logout` (ou pire) : le navigateur envoie les cookies du site visé
avec la requête, donc ta session. Le serveur croirait à ton action.

**La parade.** Un secret que l'autre site **ne peut pas connaître** : un
token rangé dans ta session (`Csrf::token()` : le génère au besoin, toujours
le même pour une session), placé dans chaque formulaire en champ caché
`_csrf_token`, et vérifié (`Csrf::verify()`) au début de **chaque** action
POST. Un site étranger peut faire soumettre un formulaire, mais ne peut pas
y mettre la valeur du token (le cookie `SameSite=Lax` de B.4 réduit déjà
cette possibilité — les deux barrières se complètent).

Remarque d'architecture : comme les actions passent par `fetch`/
XMLHttpRequest côté éditeur, `verify()` lit aussi l'en-tête
`X-CSRF-Token` en secours du champ de formulaire.

## D.9 Injection d'en-têtes email (`src/Services/Mail/MessageBuilder.php:13-18`)

**L'attaque.** Un en-tête email est une ligne `Nom: valeur`. Si une valeur
contrôlée par l'utilisateur contient un retour à la ligne (`\r\n`), tout ce
qui suit **devient un nouvel en-tête** : `Sujet: bonjour\r\nBcc:
victime@...` ajoute un destinataire caché. Le sujet et l'adresse sont
comme des « champs saisis par l'utilisateur » du protocole email.

**La parade.** `sanitizeHeaderValue()` retire **tous** `\r`, `\n` et `\0`
de toute valeur appelée à finir dans un en-tête (sujet, nom, adresse), avant
tout usage. Une seule fonction, appliquée systématiquement — même
structure de défense que `e()` : un seul point d'entrée obligatoire.

## D.10 Le pipeline d'upload (`src/Services/ImageComposer.php`)

Chaque étape répond à une attaque précise — ce tableau est la raison
d'être du fichier :

| Étape | Contre quoi |
|---|---|
| Taille max + test de l'erreur d'upload | remplissage disque / erreurs silencieuses |
| MIME lu dans le **contenu** (`finfo`), pas l'extension | renommage d'un `.php` en `.png` |
| `getimagesizefromstring` en contre-vérification | conteneurs malveillants que `finfo` peut confondre |
| `@` + test du retour `false` de GD | warnings console + rejet propre des fichiers corrompus |
| **Re-encodage** systématique en PNG (decode→encode) | métadonnées et **payloads** cachés dans les entêtes du fichier d'origine (polyglotte) |
| Nom aléatoire `bin2hex(random_bytes(16)).png` | écrasement/traversée de répertoire via le nom fourni |

Un mot sur le re-encodage, souvent le moins intuitif : un fichier peut être
**plusieurs choses à la fois** (image *et* code). Décoder en pixels puis
ré-encoder ne conserve que les pixels : tout le reste est détruit. Et
l'upload finit dans un volume servi par nginx **sans exécution PHP**
(config nginx), double barrière.

## D.11 `extract()` et `EXTR_SKIP` (retour sur C.2)

`extract()` est classé « dangereux » dans la plupart des documentations :
utilisé naïvement, il peut **écraser des variables existantes**
(`extract(['file' => '...'])` réécrirait `$file` !). Deux garde-fous ici :

1. `EXTR_SKIP` : une clé existante n'écrase **jamais** sa variable ;
2. l'entrée de `$data` est **toujours fabriquée par le code du projet**
   (contrôleurs + `View::$shared`), jamais par une entrée utilisateur —
   contrairement au piège classique `extract($_GET)`.

C'est un exemple de fonction « dangereuse » rendue sûre par son usage, plus
que par sa nature.

## D.12 Autorisation : vérifier côté serveur, toujours

Les règles du projet : chaque action privée vérifie (a) la connexion, (b)
la **propriété** (supprimer une image : l'image appartient-elle à
l'utilisateur **connecté courant** ? — pas « l'utilisateur dans le
formulaire »), et (c) le CSRF. Rien de tout ça n'est visible côté client —
le HTML/Javascript peut être falsifié. La validation JS n'existe
d'ailleurs pas dans ce projet : toute validation est **serveur**
(`Validator`), la seule qui compte.

---

# Partie E — Vivre sans framework : la table de correspondance

Ce que tu as lu est dispersé dans une vingtaine de fichiers ; cette table le
replace dans la perspective « ce qu'un framework fait pour toi / ce qu'on
a ici » :

| Besoin | Dans un framework | Ici, à la main | Fichier |
|---|---|---|---|
| Charger les classes | Composer (autoload généré) | autoloader 12 lignes | `src/bootstrap.php` (C.1) |
| Routage | annotations / config | table + regex compilées | `src/Core/Router.php`, `src/routes.php` (C.3) |
| Templates | Blade / Twig | `extract` + `ob_start` | `src/Core/View.php` (C.2) |
| Accès base de données | ORM (Eloquent…) | PDO + classes Model | `src/Core/Database.php`, `src/Models/` (C.7, D.2) |
| Validation | `$request->validate(...)` | validateur fluent maison | `src/Core/Validator.php` (C.8) |
| CSRF | middleware | `Csrf::verify()` dans chaque contrôleur | `src/Core/Csrf.php` (D.8) |
| Session | helper `session()` | wrapper statique | `src/Core/Session.php` (B.4) |
| Utilisateur courant | `Auth::user()` du framework | classe statique mémoïsée | `src/Core/Auth.php` (C.5) |
| Envoi de mail | SwiftMailer / Mailer | MIME construit à la main + `mail()` | `src/Services/Mail/` (D.9) |
| Config / secrets | package dotenv | `Env::get` sur l'environnement | `src/Core/Env.php` |
| Gestion d'erreurs | handler du framework | 3 couches dans le bootstrap | `src/bootstrap.php` (C.4) |

La colonne du milieu n'est pas là pour nostalgie : c'est la consigne du
sujet (section 1 de `PROJECT_CONTEXT.md`), et chaque ligne correspond à une
note explicative dans `explication.md`, qui déroule tout ceci **dans
l'ordre de construction du projet**.

# Partie F — Où continuer

1. **`explication.md`** — le guide complet, à lire dans l'ordre : les
   mêmes mécanismes, mais intégrés au fil des phases (Docker, auth, éditeur,
   galerie), avec le trajet complet d'une requête et les mini-cours HTTP/SQL.
2. **`COMPATIBILITY.md`** — les embûches Firefox 41 / Chrome 46 et leurs
   contournements (notamment pourquoi `canvas.toBlob` et `fetch` sont évités
   côté client).
3. **`NOTES.md`** — les outils non évidents (msmtp, GD, finfo…) et leur
   justification, ligne par ligne.
4. **`PROJECT_CONTEXT.md`** — la spec technique ; les sections 2, 9 et 11
   sont celles auxquelles il faut se référer le plus souvent.

Ordre de lecture complet conseillé : ce document → `explication.md` → le
code, `public/index.php` en premier (le trajet d'une requête y est visible
en 50 lignes).
