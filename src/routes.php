<?php
// Route table. Returns a configured Router to the front controller.
// All state-changing routes are POST and will require a CSRF token (enforced in
// controllers). This file grows as features are added.

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CommentController;
use App\Controllers\EditorController;
use App\Controllers\GalleryController;
use App\Controllers\ImageController;
use App\Controllers\LikeController;
use App\Core\Router;

$router = new Router();

// Public gallery: the home page lists every picture (phase 4).
$router->add('GET', '/', GalleryController::class, 'index');
$router->add('GET', '/images/{id}', GalleryController::class, 'show');

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

// Editor (phase 3): auth-only page and the shared capture/upload endpoint
// (the webcam fetch gets JSON, the no-JavaScript form gets a redirect).
$router->add('GET', '/editor', EditorController::class, 'index');
$router->add('POST', '/editor/capture', EditorController::class, 'capture');

// Deleting one of the user's own pictures (auth, owner, CSRF).
$router->add('POST', '/images/{id}/delete', ImageController::class, 'delete');

// Gallery actions for logged-in users (phase 4).
$router->add('POST', '/images/{id}/like', LikeController::class, 'toggle');
$router->add('POST', '/images/{id}/comments', CommentController::class, 'store');

return $router;
