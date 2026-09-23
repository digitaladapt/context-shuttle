<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Values of the Cross-Origin-Resource-Policy (CORP) response header.
 *
 * Browsers enforce CORP on "no-cors" subresource loads — images, scripts,
 * fonts, `fetch(..., {mode: 'no-cors'})` — which carry no Origin header and
 * are therefore out of reach for the CORS handshake. It is a fixed,
 * server-side policy: the header is sent on every response, and the browser
 * decides against the embedding page's own site or origin.
 *
 * "same-site" is the default because it keeps same-site embeds working while
 * blocking unrelated sites. "same-origin" is stricter still (even same-site
 * embeds are blocked); "cross-origin" opts out of embedding protection.
 *
 * The value is read from the CORS_RESOURCE_POLICY environment variable; see
 * CorsSubscriber and .env.example.
 *
 * @see https://fetch.spec.whatwg.org/#cross-origin-resource-policy-header
 */
enum CrossOriginResourcePolicy: string
{
    case SameSite = 'same-site';
    case SameOrigin = 'same-origin';
    case CrossOrigin = 'cross-origin';
}
