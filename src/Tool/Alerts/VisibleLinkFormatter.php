<?php

declare(strict_types=1);

namespace App\Tool\Alerts;

/**
 * Renders the visible destination line both providers append to a
 * notification's body. The domain is rendered bold; for example, a
 * long build URL renders as:
 *
 *     → example.com/github/actions/runs/98765/jobs/123456789012345678…
 *
 * (In real output the domain has markdown bold markers around it, and
 * the arrow is Unicode. Exact rendering is pinned by unit tests.)
 *
 * Rules, pinned by unit tests (see docs/design/ALERTS.md):
 *
 *  - domain: rightmost 50 chars — subdomains elide first, registrable
 *    domain and TLD survive; `…` prefix when cut
 *  - path: leftmost 50 chars; `…` suffix when cut; omitted when empty
 *    or just "/"
 *  - domain is bolded; query strings and fragments are dropped (noise,
 *    often long, and sometimes signed tokens that should not flash on
 *    a lock screen)
 *
 * Display-only: the full URL still rides the provider's link field
 * (ntfy `click`, Discord embed URL).
 */
final class VisibleLinkFormatter
{
    private const MAX_DOMAIN_CHARS = 50;

    private const MAX_PATH_CHARS = 50;

    /**
     * @return string|null the display line, or null when the URL cannot
     *                     be meaningfully displayed (callers skip it)
     */
    public function format(?string $url): ?string
    {
        if (null === $url || '' === trim($url)) {
            return null;
        }

        $parts = parse_url(trim($url));
        if (false === $parts || !isset($parts['host']) || '' === $parts['host']) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        $domain = $parts['host'];
        if (isset($parts['port'])) {
            $domain .= ':'.$parts['port'];
        }
        if (mb_strlen($domain) > self::MAX_DOMAIN_CHARS) {
            $domain = '…'.mb_substr($domain, -self::MAX_DOMAIN_CHARS);
        }

        $path = $parts['path'] ?? '';
        if ('/' === $path) {
            $path = '';
        }
        if (mb_strlen($path) > self::MAX_PATH_CHARS) {
            $path = mb_substr($path, 0, self::MAX_PATH_CHARS).'…';
        }

        return '→ **'.$domain.'**'.$path;
    }
}
