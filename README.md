# CDN / Edge Cache Extension for Glueful

## Overview

CDN adds edge-cache integration to your Glueful application: it generates edge
cache-control headers, purges cached content (by URL, by tag, or all), and ships
a pluggable provider-adapter system so you can target Cloudflare, Fastly, or any
custom CDN.

It plugs into the framework through the
`Glueful\Cache\Contracts\EdgeCacheInterface` seam: installing the extension binds
that interface to a real edge-cache purger, and the framework's response caching
(`ResponseCachingTrait`) consumes `generateCacheHeaders()` through the same
interface automatically — no app code change required. When no CDN provider is
configured, the extension degrades gracefully to a disabled no-op so response
handling keeps working unchanged.

## Features

- **Edge cache headers**: generates cache-control headers for edge-cached responses, consumed automatically by the framework's response caching
- **Cache purging**: purge by URL, by tag, or purge everything, through the configured provider adapter
- **Pluggable adapters**: a `provider name → adapter class` map lets you target Cloudflare, Fastly, or any custom CDN
- **Third-party adapters**: any package can register its own adapter by merging an entry into the `cdn.adapters` map
- **Degrade-to-disabled**: never throws on misconfiguration — silently no-ops (exactly like a null edge cache) when no valid provider resolves
- **`cache:purge` command**: CLI purging by URL, tag, or all
- **Seam-based**: binds the core `EdgeCacheInterface`; the framework works with or without the extension installed
- **Configurable**: provider, TTL, and per-route rules via the `cdn` config + `EDGE_CACHE_*` env

## Installation

### Installation (Recommended)

**Install via Composer**

```bash
composer require glueful/cdn

# Rebuild the extensions cache after adding new packages
php glueful extensions:cache
```

Composer discovers packages of type `glueful-extension`, but **installing does not auto-enable** them — the provider must be added to `config/extensions.php`'s `enabled` allow-list. The CLI does that for you:

```bash
# Enable (adds the provider FQCN to config/extensions.php + recompiles the cache)
php glueful extensions:enable cdn

# Disable (removes it)
php glueful extensions:disable cdn
```

In production, manage the `enabled` list in config and run `php glueful extensions:cache` in your deploy step. The extension ships no migrations.

### Local Development Installation

To develop the extension locally, register it as a Composer **path repository** in your app's `composer.json`, then require and enable it:

```jsonc
// composer.json
"repositories": [
    { "type": "path", "url": "extensions/cdn", "options": { "symlink": true } }
]
```

```bash
composer require glueful/cdn:@dev
php glueful extensions:enable cdn
```

Entries in `config/extensions.php` are plain string FQCNs (no `::class`) — prefer `extensions:enable` over editing by hand.

### Verify Installation

```bash
php glueful extensions:list
php glueful extensions:info cdn
php glueful extensions:diagnose
```

Post-install checklist:

- Rebuild cache after Composer operations: `php glueful extensions:cache`
- Confirm the seam is bound: resolving `Glueful\Cache\Contracts\EdgeCacheInterface` yields `Glueful\Extensions\Cdn\EdgeCachePurger`
- Set `EDGE_CACHE_ENABLED=true` and a `EDGE_CACHE_PROVIDER` mapped in `cdn.adapters` to activate purging

## Configuration

Configuration is loaded from the extension's `config/cdn.php` and merged under the
`cdn` key by the service provider. It reads the standard `EDGE_CACHE_*`
environment variables:

```php
return [
    // Master switch for edge caching.
    'enabled' => env('EDGE_CACHE_ENABLED', false),

    // CDN provider name. EMPTY by default — no vendor bias is shipped.
    // Must match a key in the `adapters` map below to resolve an adapter.
    'provider' => env('EDGE_CACHE_PROVIDER', ''),

    // Default TTL (seconds) for edge-cached responses.
    'default_ttl' => env('EDGE_CACHE_TTL', 3600),

    // Route-specific cache rules.
    'rules' => [
        // 'home' => ['ttl' => 600, 'vary_by' => ['Accept-Encoding']],
    ],

    // Provider name -> adapter class map (see "Registering an adapter").
    'adapters' => [],
];
```

`EdgeCachePurger` has its configuration **injected** (the `CdnServiceProvider`
factory passes `config($context, 'cdn', [])`), so the `cdn` config key is the
single source of truth and the purger stays unit-testable with any config array.

### Registering an adapter

Provider resolution is driven by two config values:

1. **`cdn.provider`** — the active provider *name* (a string).
2. **`cdn.adapters`** — a `name => adapter-class` map.

On construction the purger looks up `adapters[provider]`, requires the class to
exist and implement `CDNAdapterInterface`, and instantiates it with the full
`cdn` config array. Enable an adapter by mapping its name to its class and
selecting it:

```php
// config/cdn.php
'provider' => 'cloudflare',
'adapters' => [
    'cloudflare' => \Glueful\Extensions\Cdn\Adapters\CloudflareAdapter::class,
],
```

### Third-party adapters

Any package can ship its own adapter. It registers by **merging its
`name => class` entry into `cdn.adapters`** — for example from its own
`ServiceProvider::register()`:

```php
public function register(ApplicationContext $context): void
{
    $this->mergeConfig('cdn', [
        'adapters' => [
            'acme' => \Acme\Cdn\AcmeAdapter::class,
        ],
    ]);
}
```

The user then selects it by setting `EDGE_CACHE_PROVIDER=acme` (or
`cdn.provider => 'acme'`). The adapter class must implement
`Glueful\Extensions\Cdn\Adapters\CDNAdapterInterface` (extending
`AbstractCDNAdapter` is the easy path).

Before shipping a concrete provider adapter, follow
[docs/ADAPTER_AUTHORING.md](docs/ADAPTER_AUTHORING.md): redact provider
credentials from logs/errors, allowlist purge URL hosts to avoid SSRF, enforce
provider API timeouts, preserve the base cacheability contract, and keep adapter
construction non-fatal during boot.

### Degrade-to-disabled behavior

`EdgeCachePurger` **never throws** during adapter resolution. It silently
degrades to a disabled state (every method no-ops exactly like a null edge cache)
when:

- `cdn.provider` is unset/empty *(normal "off" config — not logged)*;
- `cdn.provider` names a key absent from `cdn.adapters` *(not logged)*;
- the mapped class does not exist, is not a `CDNAdapterInterface`, or its
  constructor throws *(logged as a warning via the PSR-3 logger, falling back to
  `error_log`)*.

While disabled: `isEnabled()` → `false`, `getProvider()` → `null`,
`generateCacheHeaders()` → `[]`, and all purge calls → `false`. This means
misconfiguration never breaks response handling. `isEnabled()` additionally
requires `cdn.enabled` to be truthy *and* an adapter to have resolved.

## Usage

### Purging via the container

```php
use Glueful\Cache\Contracts\EdgeCacheInterface;

$edge = container($context)->get(EdgeCacheInterface::class);

if ($edge->isEnabled()) {
    $edge->purgeUrl('https://example.com/page');
    $edge->purgeByTag('products');
    $edge->purgeAll();
}
```

Response caching consumes `generateCacheHeaders()` through the same interface
automatically — no app code change is needed once the extension is installed and
a provider is configured.

### Purging via the CLI

```bash
php glueful cache:purge --all
php glueful cache:purge --url=https://example.com/page
php glueful cache:purge --tag=products
```

## Requirements

- PHP 8.3 or higher
- Glueful 1.52.0 or higher

## License

MIT — licensed consistently with the Glueful framework.

## Support

For issues, feature requests, or questions, please create an issue in the repository.
