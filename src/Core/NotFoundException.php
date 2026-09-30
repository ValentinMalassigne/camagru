<?php
// Thrown by the Router when no route matches the request. Caught in the front
// controller to produce a 404 response.

declare(strict_types=1);

namespace App\Core;

class NotFoundException extends \RuntimeException
{
}
