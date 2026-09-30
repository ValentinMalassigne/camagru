<?php
// Route table. Returns a configured Router to the front controller.
// All state-changing routes are POST and will require a CSRF token (enforced in
// controllers). This file grows as features are added.

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Core\Router;

$router = new Router();

// Home page (will become the public gallery in phase 4).
$router->add('GET', '/', HomeController::class, 'index');

return $router;
