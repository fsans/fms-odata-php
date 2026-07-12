# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
