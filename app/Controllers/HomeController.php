<?php

declare(strict_types=1);

namespace App\Controllers;

use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * Landing page — pitches the app to anonymous visitors and welcomes
 * authenticated users. Household features will live further into the
 * post-auth tree once they ship.
 */
final class HomeController
{
    public function __construct(private readonly TwigAdapter $view) {}

    #[Route('/', name: 'home')]
    public function index(Request $request): Response
    {
        $isLoggedIn = Session::has('user_id');
        $email = $isLoggedIn ? (string) Session::get('username', '') : null;

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('home.twig', [
                'is_logged_in' => $isLoggedIn,
                'session_email' => $email,
            ]));
    }
}
