<?php
// HomeController: renders the home page. In the walking skeleton this is a
// welcome placeholder; phase 4 replaces it with the public gallery listing.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;

class HomeController
{
    /**
     * GET / — home page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $html = View::renderPage('pages/home.php', [
            'title' => 'Camagru',
        ]);
        return Response::make($html);
    }
}
