<?php

declare(strict_types=1);

namespace App\View;

use Karhu\Middleware\Csrf;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes karhu's CSRF helpers as Twig functions.
 *
 * - {{ csrf_field() }} — renders the hidden form input
 * - {{ csrf_token() }} — returns the raw token (for AJAX X-CSRF-Token headers)
 *
 * The csrf_field function is declared `is_safe: ['html']` so Twig will not
 * auto-escape the pre-built input markup. Preferred over wrapping the
 * return value in Twig\Markup: that pattern fails silently on a wrong
 * import (you get escaped HTML back).
 */
final class CsrfTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'csrf_field',
                static fn(): string => Csrf::field(),
                ['is_safe' => ['html']],
            ),
            new TwigFunction('csrf_token', static fn(): string => Csrf::token()),
        ];
    }
}
