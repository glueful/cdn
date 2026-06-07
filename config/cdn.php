<?php

/**
 * CDN / Edge Cache Configuration
 *
 * Configures the CDN extension's edge-caching behaviour. Mirrors the legacy
 * framework `cache.edge` block (same EDGE_CACHE_* env vars) but is owned by
 * this extension and re-keyed under `cdn`.
 *
 * The `provider` default is an EMPTY string on purpose: shipping a vendor
 * default (e.g. cloudflare) would bias every install toward one provider. An
 * empty provider, or one absent from the `adapters` map, degrades the edge
 * cache to disabled (no-op) rather than failing.
 */

return [
    // Master switch for edge caching.
    'enabled' => env('EDGE_CACHE_ENABLED', false),

    // CDN provider name. Empty by default: no vendor bias is shipped.
    // Must match a key in the `adapters` map below to resolve an adapter.
    'provider' => env('EDGE_CACHE_PROVIDER', ''),

    // Default time-to-live (seconds) for edge-cached responses.
    'default_ttl' => env('EDGE_CACHE_TTL', 3600),

    // Route-specific cache rules can be defined here.
    'rules' => [
        // 'home' => ['ttl' => 600, 'vary_by' => ['Accept-Encoding']],
    ],

    // Provider name -> adapter class map.
    //
    // Shipped empty: concrete provider adapters (Cloudflare, Fastly, ...) are a
    // packaging follow-up. Add entries like:
    //   'cloudflare' => \Glueful\Extensions\Cdn\Adapters\CloudflareAdapter::class,
    'adapters' => [],
];
