# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Script execution** — `ScriptInvoker` class providing FileMaker script
  invocation at three scopes (database, entity-set, record) by name or by
  FMSID (v26+). Integrated into `Client::script()`, `Client::scriptById()`,
  `Query::script()`, and `EntityRef::script()`. Non-zero script errors are
  promoted to `FMScriptError`. Handles both the nested (v26+) and flat
  (older FMS) response envelopes via the spec-php `Scripts` facade.
- **Container field I/O** — `ContainerRef` class providing container
  download (`get()`, `getStream()`), upload (binary and base64 encoding),
  and clear (`delete()`). MIME sniffing from magic bytes, Content-Disposition
  parsing (RFC 6266 + RFC 5987), and base64 JSON annotation building are
  reused from the spec-php `Containers` facade. Integrated into
  `EntityRef::container()` and `EntityRef::patchContainers()` (multi-field
  container + regular field updates in a single PATCH).
- `ContainerDownloadResult` DTO extending the spec-php
  `ContainerDownload` shape with a `size` field for parity with the JS and
  Python clients.
- `ResponseFactory::binary()` test helper for binary response construction.
- 41 new tests covering script invocation (URLs, scopes, FMSID, envelopes,
  error promotion) and container I/O (download, upload, sniffing, base64,
  clear, patchContainers, Content-Disposition parsing).

### Docs

- README: updated Status section (no longer "MVP"), added script and
  container usage examples to Quick start, added ScriptInvoker and
  ContainerRef to Architecture, added Roadmap section listing unported
  features ($batch, schema DDL, webhooks), updated test count to 238.

## [1.0.1] — 2026-07-15

### Fixed

- Cast `preg_match` opening-tag offset to `int` in `MetadataParser` to
  satisfy static analysis (`PREG_OFFSET_CAPTURE` returns `int|string`
  depending on PHP version).
- Cast `preg_match` close-tag offset to `int` in `MetadataParser` for
  the same reason.

### Docs

- Add status badges (PHP, OData, FileMaker, runtime deps, license, spec,
  DeepWiki) to `README.md`.

## [1.0.0] — 2025-07-12

### Added

- Initial workspace scaffold: composer.json, PHPUnit and PHPStan
  configuration, CI workflow, README, LICENSE, and AGENTS.md.
- Project depends on `fsans/fms-odata-spec-php` ^2.0 for shared types,
  error hierarchy, auth helpers, and URL literal formatting.
- `ext-curl` declared as a runtime requirement.
- `ClientOptions` DTO with host, database, token (static or closure-based),
  optional 401-refresh callback, custom transport, timeout, and SSL
  verification settings.
- `Client` and `FMSOData` entry point with base URL construction
  (`https://host/fmi/odata/v4/<database>`), trailing-slash trimming, and
  database name encoding.
- Neutral HTTP types: `Request`, `Response`, `TransportInterface`,
  `TransportException`, `HttpRequestOptions`, `ResponseType`.
- `CurlTransport` — production cURL-backed transport with SSL verification,
  header parsing, and timeout support.
- `HttpClient` — auth header injection (Basic/Bearer normalization via
  spec-php), OData-Version/OData-MaxVersion headers, Accept header by
  response type, one-time 401 retry with refresh callback, JSON/XML error
  body parsing, and typed error mapping (400 -> FMValidationError,
  401 -> FMAuthError, 404 -> FMNotFoundError, others -> FMODataError).
- `Url` utility class: `encodePathSegment`, `odataEncode` (preserves commas,
  `$`, `=`, `;`, `()`, `'` as literals), `buildQueryString`,
  `formatDateTime`, `parseDateTime`, `formatKey`.
- `Filter` and `FilterFactory` — fluent OData $filter expression builder
  with eq/ne/gt/ge/lt/le, startsWith/endsWith/contains, and/or/not, raw
  expressions, and literal formatting (strings, ints, floats, booleans,
  null, DateTime).
- `Query` builder — select, filter (Filter/string/closure), orWhere, expand
  (with nested options), orderBy, top, skip, count, URL serialization with
  deterministic option order, get() (collection parsing with @odata.count
  and @odata.nextLink), create() (POST with JSON body).
- `EntityRef` — single-record handle with byKey URL construction, get(),
  fieldValue() (single-field unwrap), patch() (with Prefer and If-Match
  headers), delete() (with If-Match).
- `MetadataParser` — regex-based CSDL parser for entity types, properties,
  keys, nullable/maxLength, entity sets, and actions. Handles prefixed
  elements and self-closing tags.
- `MetadataFetcher` — fetches and caches `$metadata` XML, with refresh
  option and raw/parsed cache layers.
- Version detection: `Client::serverVersion()`, `Client::version()`
  (maps to FMVersionMajor enum), `Client::versionInfo()`, and
  `Client::hasFeature()` for feature gating.
- Low-level request methods: `Client::request()` (JSON-decoded) and
  `Client::rawRequest()` (raw Response), with relative/absolute/leading-slash
  URL resolution.
- Test infrastructure: `MockTransport` (handler-based, captures requests)
  and `ResponseFactory` (JSON/XML/text/no-content/error builders).
- 197 tests covering ClientOptions validation, base URL construction, URL
  encoding, filter expressions, query serialization and execution, entity
  CRUD, metadata parsing and caching, version detection and feature gates,
  HttpClient auth/headers/retry/error-parsing, and CurlTransport.
