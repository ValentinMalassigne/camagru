# Camagru — Definitions : les notions du projet, expliquées

Ce fichier regroupe des explications courtes et autonomes des notions,
patterns et concepts utilisés dans le projet. Chaque entrée répond à trois
questions, dans le même esprit qu'`introduction.md` :

1. **ce que c'est / quel problème ça résout** (le problème avant la solution) ;
2. **comment ça marche ici**, sur le code réel du projet ;
3. **où le retrouver** (`chemin/fichier.php`).

Sommaire :

- [Front controller pattern](#front-controller-pattern)

---

## Front controller pattern

Le **front controller pattern**, c'est le choix d'avoir **un seul point
d'entrée** pour toutes les requêtes HTTP de l'application, au lieu d'avoir
un fichier PHP par page.

**Le problème qu'il résout.** Sans lui, l'approche « naturelle » en PHP
serait : `login.php` pour la page de login, `gallery.php` pour la galerie,
`delete-image.php` pour supprimer… Chaque fichier devrait alors répéter
lui-même les mêmes tâches transversales : charger la config, ouvrir la
session, vérifier le CSRF, gérer les erreurs, inclure l'autoloader. Sur
20 pages, ça fait 20 copies du même code de démarrage — et la première qui
l'oublie devient un trou de sécurité (imagine une page POST qui oublie
`Csrf::verify()`).

**La solution.** Un seul fichier reçoit *toutes* les requêtes. Dans ce
projet :

```
requête HTTP
   └── nginx (règle : « si ce n'est pas un fichier statique, envoie à index.php »)
         └── public/index.php  ← le front controller
               ├── src/bootstrap.php   (autoloader, session, erreurs — une seule fois)
               ├── Router::dispatch()  (trouve la route)
               └── new $controllerClass()->$method()  (exécute la bonne action)
```

L'URL elle-même ne correspond à aucun fichier : c'est le **routeur**
(`src/Core/Router.php`, expliqué dans `introduction.md` partie C.3) qui
traduit `/images/42` en « méthode `show` de `GalleryController` ».

**Trois bénéfices concrets dans Camagru :**

1. **Un seul endroit pour le code commun** — session, gestion d'erreurs en
   3 couches, `View::share()` : écrits une fois dans `bootstrap.php`/
   `index.php`, appliqués à *toutes* les pages sans exception possible.
2. **Sécurité uniforme** — impossible qu'une page « oublie » une
   vérification, puisqu'elles passent toutes par le même couloir.
3. **Extension sans duplication** — ajouter une page = une ligne dans
   `routes.php` + un contrôleur ; `index.php` ne change jamais.

**Le point important** : ce n'est **pas** PHP qui impose ce pattern. PHP
accepterait parfaitement 30 fichiers à la racine. C'est une décision
d'architecture — celle que font Symfony, Laravel, Zend, etc.
(`public/index.php` chez Laravel fait exactement le même job), et Camagru
la réécrit à la main parce que les frameworks sont interdits par le sujet.

En une phrase : *plutôt que 30 portes qui ouvrent chacune sur une pièce,
une seule porte et un plan du bâtiment (le routeur).*

**Où le retrouver :** `public/index.php` (~50 lignes, le trajet complet d'une
requête y est visible), règle nginx
`try_files $uri /index.php?$query_string` dans
`docker/nginx/default.conf`, table des routes dans `src/routes.php`.
