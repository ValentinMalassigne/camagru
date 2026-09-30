<?php
// Route table. Returns a configured Router to the front controller.
// All state-changing routes are POST and will require a CSRF token (enforced in
// controllers). This file grows as features are added.

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Core\Router;

$router = new Router();

// Home page (will become the public gallery in phase 4).
$router->add('GET', '/', HomeController::class, 'index');

// Registration and email confirmation (phase 2.1).
$router->add('GET', '/register', AuthController::class, 'registerForm');
$router->add('POST', '/register', AuthController::class, 'register');
$router->add('GET', '/verify', AuthController::class, 'verify');

// Login and logout (phase 2.2).
$router->add('GET', '/login', AuthController::class, 'loginForm');
$router->add('POST', '/login', AuthController::class, 'login');
$router->add('POST', '/logout', AuthController::class, 'logout');

// Password reset (phase 2.3).
$router->add('GET', '/forgot-password', AuthController::class, 'forgotPasswordForm');
$router->add('POST', '/forgot-password', AuthController::class, 'forgotPassword');
$router->add('GET', '/reset-password', AuthController::class, 'resetPasswordForm');
$router->add('POST', '/reset-password', AuthController::class, 'resetPassword');

// Account page (phase 2.4).
$router->add('GET', '/account', AccountController::class, 'index');
$router->add('POST', '/account', AccountController::class, 'update');

return $router;
