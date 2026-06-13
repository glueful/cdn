# CDN Adapter Authoring

Concrete CDN adapters are responsible for provider API calls. Keep these rules
load-bearing:

1. Never log credentials. Redact `api_key`, `api_token`, `token`, `key`,
   `secret`, `Authorization`, and provider-specific credential fields in errors,
   debug output, and thrown exception messages.
2. Validate purge URLs before sending provider API requests. Allowlist expected
   hosts from configuration and reject private, loopback, link-local, and
   otherwise internal addresses after DNS resolution. Do not let an arbitrary
   purge URL become an SSRF primitive.
3. Enforce network timeouts on every provider API call. Timeouts should be
   configurable, bounded, and have a safe default.
4. Preserve the base adapter's cacheability contract. Authenticated requests,
   cookie-bearing requests, and responses that set cookies are not cacheable by
   default.
5. Keep provider failures non-fatal during boot. Construction failure should
   degrade the purger to disabled through the service provider path rather than
   breaking application startup.
