# Glueful CDN / Edge Cache

CDN / edge-cache integration for the [Glueful framework](https://github.com/glueful/framework):
edge cache-control headers, cache purging, and a pluggable provider-adapter
system (Cloudflare, Fastly, …).

This extension owns code that previously lived in framework core
(`Glueful\Cache\EdgeCacheService`, `Glueful\Cache\CDN\*`, the `cache:purge`
command). Core now keeps only the seam — the
`Glueful\Cache\Contracts\EdgeCacheInterface` contract and a no-op
`Glueful\Cache\NullEdgeCache` default — so response caching keeps working with
or without this extension installed. Installing the extension rebinds that
interface to a real implementation.

## What it provides

- **`Glueful\Extensions\Cdn\EdgeCachePurger`** — implements the core
  `Glueful\Cache\Contracts\EdgeCacheInterface`. Generates edge cache headers and
  purges content (by URL, by tag, or all) through the configured provider
  adapter.
- **`Glueful\Extensions\Cdn\Adapters\{CDNAdapterInterface, AbstractCDNAdapter}`**
  — the contract and base class for provider adapters.
- **`cache:purge`** console command — registered only when this extension is
  installed.

## Installation

```bash
composer require glueful/cdn
```

The extension is auto-discovered via its `composer.json` `extra.glueful`
provider (`Glueful\Extensions\Cdn\CdnServiceProvider`). On boot it:

1. merges its `config/cdn.php` defaults under the `cdn` config key,
2. binds `EdgeCacheInterface` → `EdgeCachePurger` in the container (so resolving
   the interface now yields the real purger instead of the core `NullEdgeCache`),
3. discovers and registers the `cache:purge` command.

No manual `extensions:enable` step is required for a Composer-installed package;
if you manage extensions explicitly, enable it with:

```bash
php glueful extensions:enable Cdn
```

## Configuration

Configuration lives under the `cdn` key (`config/cdn.php`), re-keyed from the old
core `cache.edge` block but reading the **same** `EDGE_CACHE_*` environment
variables:

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

### Constructor change vs. the old core service (intentional)

The old core `EdgeCacheService` constructor **read `cache.edge` itself**. The
extension's `EdgeCachePurger` instead has its configuration **injected**:

```php
public function __construct(ApplicationContext $context, array $config)
```

The `CdnServiceProvider` factory passes `config($context, 'cdn', [])` as
`$config`. This is deliberate: it removes the hidden global-config dependency,
keeps the purger unit-testable (pass any config array), and makes the `cdn`
config key the single source of truth. Do not reintroduce a `cache.edge` read.

## Registering an adapter

Provider resolution is driven by two config values:

1. **`cdn.provider`** — the active provider *name* (a string).
2. **`cdn.adapters`** — a `name => adapter-class` map.

On construction the purger looks up `adapters[provider]`, requires the class to
exist and implement `CDNAdapterInterface`, and instantiates it with the full
`cdn` config array (`new $adapterClass($config)` — see `AbstractCDNAdapter`,
whose constructor accepts `array $config`).

A first-party adapter is enabled simply by mapping its name to its class and
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

## Degrade-to-disabled behavior

`EdgeCachePurger` **never throws** during adapter resolution. It silently
degrades to a disabled state (every method no-ops exactly like the core
`NullEdgeCache`) when:

- `cdn.provider` is unset/empty *(normal "off" config — not logged)*;
- `cdn.provider` names a key absent from `cdn.adapters` *(not logged)*;
- the mapped class does not exist, is not a `CDNAdapterInterface`, or its
  constructor throws *(logged as a warning via the PSR-3 logger, falling back to
  `error_log`)*.

While disabled: `isEnabled()` → `false`, `getProvider()` → `null`,
`generateCacheHeaders()` → `[]`, and all purge calls → `false`. This matches the
no-extension core behavior, so misconfiguration never breaks response handling.

`isEnabled()` additionally requires `cdn.enabled` to be truthy *and* an adapter
to have resolved.

## Usage

```php
use Glueful\Cache\Contracts\EdgeCacheInterface;

$edge = container($context)->get(EdgeCacheInterface::class);

if ($edge->isEnabled()) {
    $edge->purgeUrl('https://example.com/page');
    $edge->purgeByTag('products');
    $edge->purgeAll();
}
```

Response caching (the framework's `ResponseCachingTrait`) consumes
`generateCacheHeaders()` through the same interface automatically — no app code
change is needed once the extension is installed and a provider is configured.

CLI:

```bash
php glueful cache:purge --all
php glueful cache:purge --url=https://example.com/page
php glueful cache:purge --tag=products
```

(Without this extension installed, `cache:purge` is not registered at all.)

## Development

```bash
composer test      # PHPUnit
composer analyze   # PHPStan
composer phpcs     # PSR-12 coding standard
```
