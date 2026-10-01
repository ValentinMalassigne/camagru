<?php
// ImageException: thrown by ImageComposer when an upload or an overlay is
// rejected. The message is safe to show to the user: it never contains a
// path, a query or any internal detail.

declare(strict_types=1);

namespace App\Services;

class ImageException extends \RuntimeException
{
}
