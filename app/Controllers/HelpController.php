<?php

declare(strict_types=1);

namespace App\Controllers;

use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\View\TwigAdapter;
use League\CommonMark\CommonMarkConverter;

/**
 * v0.5.2 — in-product help. Renders docs/USERGUIDE.md (the canonical source
 * of truth) as HTML via league/commonmark, wrapped in a layout.twig page.
 *
 * Single source: the same Markdown file shows on GitHub for any contributor
 * who finds the repo AND in-product for any family member who finds the
 * footer link. No duplication, no drift.
 *
 * Anonymous-accessible (no session required) so a user who can't sign in
 * (forgot their password and is trying to remember how to recover) can
 * still read the recovery walkthrough.
 */
final class HelpController
{
    /** Filesystem path to the Markdown source. */
    private const USERGUIDE_PATH = __DIR__ . '/../../docs/USERGUIDE.md';

    public function __construct(
        private readonly TwigAdapter $view,
        private readonly NavContext $nav,
    ) {}

    #[Route('/help', methods: ['GET'], name: 'help')]
    public function show(Request $request): Response
    {
        $markdown = is_readable(self::USERGUIDE_PATH)
            ? (string) file_get_contents(self::USERGUIDE_PATH)
            : '# Help unavailable' . "\n\nThe user guide source file isn't readable. Ask the operator to check `docs/USERGUIDE.md`.";

        // CommonMark defaults are safe: HTML in the source is escaped, no raw
        // `<script>` passthrough. Our doc is fully under our control anyway —
        // we'd flip allow-unsafe only if we trusted the source, which we do,
        // but the safer default doesn't hurt us.
        $html = (new CommonMarkConverter())->convert($markdown)->getContent();

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('help.twig', [
                'guide_html' => $html,
            ] + $this->nav->forCurrentUser()));
    }
}
