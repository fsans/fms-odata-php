# AGENTS.md — fms-odata-php

Guidance for AI agents (Devin, Claude, Cursor, etc.) working on this repository.

## Project type

This is a **PHP client library** for the Claris FileMaker Server OData v4 API.
It is the PHP mirror of [fms-odata-js](https://github.com/fsans/fms-odata-js)
and [fms-odata-py](https://github.com/fsans/fms-odata-py).

It depends on [`fsans/fms-odata-spec-php`](https://packagist.org/packages/fsans/fms-odata-spec-php)
for shared types, error hierarchy, auth helpers, and URL literal formatting.
Spec types are not redefined locally — reuse them from the spec package.

## Spec alignment

Aligned with the [fms-odata-spec](https://github.com/fsans/fms-odata-spec)
reference specification. See `docs/14-reconciliation.md` in the spec repo for
the full alignment plan.

## Build commands

```bash
composer install        # install dependencies
composer test           # PHPUnit (197 tests)
composer analyse        # PHPStan level max
composer check          # tests + static analysis
```

## Key conventions

- **No emojis** in any generated markdown or code.
- **Reuse spec-php types.** Error classes, auth helpers, URL literal
  formatting, and version/feature matrices are sourced from
  `fsans/fms-odata-spec-php`. Do not redefine them locally.
- **PHP 8.2+.** Use readonly classes, constructor property promotion, and
  string-backed enums where applicable (matching the spec-php style).
- **PSR-4 namespace**: `FmsOData\` maps to `src/`. Test namespace
  `FmsOData\Tests\` maps to `tests/`.
- **No runtime Composer dependencies** beyond `fsans/fms-odata-spec-php`.
  The HTTP transport uses PHP's built-in cURL extension (`ext-curl`).

## Architecture

The client is layered into pure request builders and an HTTP execution layer:

- **Query, Filter, EntityRef, Url** — build pure request data (URLs, filter
  expressions, key references). No direct cURL calls.
- **HttpClient** — handles auth, OData headers, 401 retry, JSON/XML decoding,
  and error conversion. Uses a `TransportInterface` for the actual HTTP call.
- **CurlTransport** — the production transport, using PHP's built-in cURL
  extension. The only transport shipped with the MVP.
- **MockTransport** (test only) — deterministic in-memory transport for
  offline test execution.
- **MetadataParser / MetadataFetcher** — parse `$metadata` XML and cache the
  result.
- **Client / FMSOData** — the entry point, wiring everything together and
  providing version detection and feature gates.

## Key source files

- `src/Client.php` — `Client` entry point, base URL construction, version
  detection, feature gating, low-level request methods
- `src/FMSOData.php` — thin alias subclass of `Client`
- `src/ClientOptions.php` — configuration DTO (host, database, token,
  transport, timeout, SSL)
- `src/Url.php` — OData URL encoding helpers (reuses spec-php literals)
- `src/Http/HttpClient.php` — auth, OData headers, 401 retry, error parsing
- `src/Http/CurlTransport.php` — cURL-backed production transport
- `src/Http/TransportInterface.php` — single request/response contract
- `src/Http/Request.php`, `Response.php` — neutral HTTP DTOs
- `src/Query/Query.php` — fluent query builder + serialization + execution
- `src/Query/Filter.php`, `FilterFactory.php` — $filter expression builder
- `src/Entity/EntityRef.php` — single-record CRUD handle
- `src/Entity/EntityWriteOptions.php` — PATCH/DELETE options (If-Match,
  return representation)
- `src/Metadata/MetadataParser.php` — regex-based CSDL XML parser
- `src/Metadata/MetadataFetcher.php` — cached $metadata fetcher

## FileMaker OData quirks handled

- URL encoding: spaces are `%20` (not `+`), commas, `$`, `=`, `;`, `(`, `)`,
  and `'` are preserved as literal characters in query strings.
- Required headers: `OData-Version: 4.0` and `OData-MaxVersion: 4.0` on
  every request.
- Error responses: both JSON (`{error: {code, message}}`) and XML
  (`<m:error>`) error bodies are parsed.
- Version detection: from `$metadata` XML `ProductVersion`/`ServerVersion`
  annotations (4 strategies, priority order).
- `Prefer` header: `return=minimal` (default) or `return=representation`.
- `ETag`/`If-Match`: for optimistic concurrency on PATCH and DELETE.

## Branching model (Git Flow)

- **`main`**: Stable releases only. Every commit is a merge from `develop`
  and is tagged with an annotated version tag.
- **`develop`**: Active development branch. All work lands here first.
- Feature branches (optional): branch off `develop`, merge back into `develop`.

**When making changes:**

1. Commit work to `develop`.
2. When ready for release, merge `develop` into `main`.
3. Tag the release on `main` with an annotated tag: `git tag -a vMAJOR.MINOR.PATCH -m "..."`.
4. Push everything: `git push origin develop main --tags`.

**Tag rules:**

- Semantic versioning: `vMAJOR.MINOR.PATCH` (e.g. `v0.1.0`).
- Annotated tags only (`git tag -a`), never lightweight tags.
- Tags only on `main`, never on `develop`.

## Commit message format

Follow [Conventional Commits](https://www.conventionalcommits.org/):

- `feat:` — new feature
- `fix:` — bug fix
- `docs:` — documentation
- `test:` — tests
- `refactor:` — code refactoring
- `chore:` — build/tooling
- `feat!:` / `fix!:` — breaking change (append `!` after the type)

## Pre-PR checklist

- [ ] Tests pass: `composer test`
- [ ] Static analysis passes: `composer analyse`
- [ ] `composer check` succeeds
- [ ] `CHANGELOG.md` updated (if user-facing change)
