# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

### Added

- **Discovery-path regression test.** Loads the provider through the framework's real
  extension-discovery dispatch (`defs()` pass-through, else `services()` via the DSL loader),
  guarding against typed `Definition` objects being returned from `services()` — a regression the
  existing `Container::load()`-based tests cannot catch.

### Fixed

- **CLI option accuracy.** Removed the unimplemented `cache:purge --provider` and `--timeout`
  options; provider selection remains config-driven through `cdn.provider`.
- **Default cacheability safety.** The base adapter now refuses to cache authenticated requests,
  cookie-bearing requests, and responses that set cookies, and treats `Cache-Control` directives
  case-insensitively.
- **Wildcard route matching.** Route cache rules now escape regex metacharacters before expanding
  `*`, so patterns such as `admin.*` only match a literal dot plus suffix.
- **Boot compatibility with framework 1.55.** The service provider declared its bindings via the
  DSL `services()` method but returned strongly-typed `DefinitionInterface` objects, which the
  framework's DSL service loader rejects (`"Service '<id>' must be an array"`). Under framework
  1.55 this threw during boot in dev/test and silently dropped the bindings in production. The
  method is now `defs()`, the strongly-typed pass-through path that accepts `DefinitionInterface`
  objects.

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
