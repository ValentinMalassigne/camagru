<?php
// Front controller: the only PHP file nginx exposes. Every request enters here.
// It bootstraps the app, builds the request, matches a route, and dispatches it.

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Csrf;

// Build the request from the global environment.
$request = Request::fromGlobals();

// Share data every template needs: the CSRF token (for the logout form) and
// the logged-in user (null for visitors).
View::share([
    'currentUser' => Auth::user(),
    'csrfToken'   => Csrf::token(),
]);

// Load the route table and match the incoming request.
$router = require __DIR__ . '/../src/routes.php';

try {
    $route = $router->match($request->method(), $request->path());
} catch (App\Core\NotFoundException $e) {
    // No route matched: 404 page (full layout).
    $response = Response::make(View::renderPage('pages/404.php', ['title' => 'Page not found']), 404);
    $response->send();
    return;
}

// Route handler is [ControllerClass, method]; resolve it with the DI-light container.
[$controllerClass, $method] = $route['handler'];
$controller = new $controllerClass();

try {
    // Call the controller action, passing the request and any path params.
    $response = $controller->$method($request, $route['params']);
} catch (App\Core\NotFoundException $e) {
    $response = Response::make(View::renderPage('pages/404.php', ['title' => 'Page not found']), 404);
} catch (Throwable $e) {
    // Any uncaught throwable: log it (via the bootstrap handler) and show a
    // minimal standalone 500 page so the view system can't recurse on itself.
    app_log_throwable($e);
    $response = Response::make(View::render('pages/500.php'), 500);
}

$response->send();
