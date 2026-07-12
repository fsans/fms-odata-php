# fms-odata-php

PHP client for the Claris FileMaker Server OData v4 API.

Functional equivalent of [fms-odata-js](https://github.com/fsans/fms-odata-js)
and [fms-odata-py](https://github.com/fsans/fms-odata-py).

## Status

**MVP** — core client functionality is implemented: configuration, cURL-backed
HTTP transport with auth and 401 retry, fluent query builder, entity CRUD,
metadata parsing, version detection, and feature gating.

## Spec alignment

This library is aligned with the
[fms-odata-spec](https://github.com/fsans/fms-odata-spec) reference
specification and depends on
[`fsans/fms-odata-spec-php`](https://packagist.org/packages/fsans/fms-odata-spec-php)
for shared types, error hierarchy, auth helpers, and URL literal formatting.

## Requirements

- PHP 8.2 or later
- `ext-curl` (the cURL extension)

## Installation

```bash
composer require fsans/fms-odata-php
```

## Quick start

```php
use FmsOData\FMSOData;

$client = FMSOData::create(
    host: 'https://fms.example.com',
    database: 'Contacts',
    token: FmsOData\Spec\Auth\Auth::basicAuth('user', 'password'),
);

// Query records
$result = $client->from('Contacts')
    ->select('id', 'name', 'email')
    ->filter(fn (FilterFactory $f) => $f->eq('active', true))
    ->orderBy('name')
    ->top(10)
    ->get();

foreach ($result->value as $record) {
    echo $record['name'] . "\n";
}

// Get a single record by key
$record = $client->from('Contacts')->byKey(42)->get();

// Create a record
$created = $client->from('Contacts')->create(['name' => 'Jane', 'email' => 'jane@example.com']);

// Update a record (PATCH)
$client->from('Contacts')->byKey(42)->patch(['name' => 'Jane Doe']);

// Update with optimistic concurrency
$client->from('Contacts')->byKey(42)->patch(
    ['name' => 'Jane Doe'],
    new EntityWriteOptions(ifMatch: 'W/"abc123"'),
);

// Delete a record
$client->from('Contacts')->byKey(42)->delete();

// Fetch metadata
$metadata = $client->metadata();
echo $metadata->namespace . "\n";
foreach ($metadata->entityTypes as $type) {
    echo "  Table: {$type->name}\n";
}

// Detect server version
$version = $client->version();       // FMVersionMajor::V21
$info = $client->versionInfo();      // FMVersionInfo
$client->hasFeature('webhooks');     // bool
```

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

Shared DTOs, enums, error classes, auth helpers, and URL literal formatting
are sourced from `FmsOData\Spec` (the `fsans/fms-odata-spec-php` package).

## FileMaker OData quirks handled

- URL encoding: spaces are `%20` (not `+`), commas, `$`, `=`, `;`, `(`, `)`,
  and `'` are preserved as literal characters in query strings.
- Required headers: `OData-Version: 4.0` and `OData-MaxVersion: 4.0` are sent
  on every request.
- Error responses: both JSON (`{error: {code, message}}`) and XML
  (`<m:error>`) error bodies are parsed.
- Version detection: from `$metadata` XML `ProductVersion`/`ServerVersion`
  annotations.
- `Prefer` header: `return=minimal` (default) or `return=representation`.
- `ETag`/`If-Match`: for optimistic concurrency on PATCH and DELETE.

## Development

```bash
composer install
composer test        # PHPUnit (197 tests)
composer analyse     # PHPStan level max
composer check       # tests + static analysis
```

## License

MIT — see [LICENSE](LICENSE).
