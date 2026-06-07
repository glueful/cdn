# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [1.0.0] - 2026-06-07 — Initial release (extracted from Glueful framework 1.52.0)

CDN / edge-cache integration, extracted from framework core in **Glueful framework 1.52.0**.
Requires `glueful/framework >=1.52.0`, which removed these classes from core and retained
only the `Glueful\Cache\Contracts\EdgeCacheInterface` seam (plus a no-op `NullEdgeCache`
default) that this extension binds.

### Added

- **`EdgeCachePurger`** implementing the retained core
  `Glueful\Cache\Contracts\EdgeCacheInterface` seam — generates edge cache headers and
  purges content by URL, by tag, or all, through the configured provider adapter. Its
  config is injected (the `CdnServiceProvider` factory passes `config($context, 'cdn', [])`),
  and it **degrades to disabled** (never throws) on any adapter-resolution failure, matching
  the core null-edge-cache behavior. `CdnServiceProvider` binds the interface to it
  (last-provider-wins over the core no-op default).
- **`Adapters\CDNAdapterInterface` and `Adapters\AbstractCDNAdapter`** — the contract and
  base class for pluggable provider adapters, resolved from the `cdn.provider` name +
  `cdn.adapters` class map.
- **`cache:purge`** console command (auto-discovered from `Console/`) — purge by `--url`,
  `--tag`, or `--all`; registered only when this extension is installed.
- **`config/cdn.php`** (the `cdn` config key + `EDGE_CACHE_*` env), merged via the provider's
  `register()`.

### Migration from framework core

Namespace map for any app/extension code referencing the moved classes:

```
Glueful\Cache\EdgeCacheService          →  Glueful\Extensions\Cdn\EdgeCachePurger
Glueful\Cache\CDN\CDNAdapterInterface   →  Glueful\Extensions\Cdn\Adapters\CDNAdapterInterface
Glueful\Cache\CDN\AbstractCDNAdapter    →  Glueful\Extensions\Cdn\Adapters\AbstractCDNAdapter
```

`Glueful\Cache\Contracts\EdgeCacheInterface` is unchanged and stays in core (the seam this
extension binds). The `cdn` config block reads the same `EDGE_CACHE_*` environment variables
as the old core `cache.edge` block.
