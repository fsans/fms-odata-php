# fms-odata-php

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![OData](https://img.shields.io/badge/OData-v4-0078D4?logo=data&logoColor=white)](https://www.odata.org/)
[![FileMaker](https://img.shields.io/badge/FileMaker-20.0--26.0-FF6B00?logo=filemaker&logoColor=white)](https://www.claris.com/filemaker/)
[![Deps](https://img.shields.io/badge/runtime%20deps-1-blue)](./composer.json)
[![License](https://img.shields.io/badge/license-MIT-black)](./LICENSE)
[![Spec](https://img.shields.io/badge/spec-fms--odata--spec-FF6B00?logo=filemaker&logoColor=white)](https://github.com/fsans/fms-odata-spec)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/fsans/fms-odata-php)

PHP client for the Claris FileMaker Server OData v4 API.

Functional equivalent of [fms-odata-js](https://github.com/fsans/fms-odata-js)
and [fms-odata-py](https://github.com/fsans/fms-odata-py).

## Status

Core client functionality is implemented: configuration, cURL-backed HTTP
transport with auth and 401 retry, fluent query builder, entity CRUD,
script execution, container field I/O, metadata parsing, version
detection, and feature gating.

> **Note:** `$batch`, schema DDL, and webhook management are not yet
> ported from the JS/Python clients. See the roadmap section below.

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

// Run a FileMaker script at database scope
$result = $client->script('Greet', parameter: 'world');
echo $result->resultParameter;  // text result from Exit Script

// Run a script by FMSID (v26+; survives renames)
$client->scriptById(42, parameter: 'hello');

// Run a script at entity-set scope
$client->from('Contacts')->script('ValidateAll');

// Run a script at record scope (current record = this entity)
$client->from('Contacts')->byKey(42)->script('Notify', parameter: 'updated');

// Download a container field
$dl = $client->from('Contacts')->byKey(42)->container('Photo')->get();
echo $dl->size;           // byte length
echo $dl->contentType;    // e.g. image/png
echo $dl->filename;       // parsed from Content-Disposition
\file_put_contents('/tmp/photo.png', $dl->data);

// Upload to a container field (binary, MIME auto-sniffed from magic bytes)
$client->from('Contacts')->byKey(42)->container('Photo')->upload(
    data: \file_get_contents('/tmp/photo.png'),
    filename: 'photo.png',
);

// Upload via base64 JSON (for multi-field or mixed container+regular updates)
use FmsOData\Spec\Containers\ContainerEncoding;
$client->from('Contacts')->byKey(42)->container('Photo')->upload(
    data: \file_get_contents('/tmp/photo.png'),
    contentType: 'image/png',
    filename: 'photo.png',
    encoding: ContainerEncoding::BASE64,
);

// Update multiple container fields + regular fields in one request
$client->from('Contacts')->byKey(42)->patchContainers(
    [
        'Photo' => ['data' => $pngBytes, 'contentType' => 'image/png', 'filename' => 'p.png'],
        'Doc'   => ['data' => $pdfBytes, 'contentType' => 'application/pdf'],
    ],
    ['name' => 'Jane'],
);

// Clear a container field
$client->from('Contacts')->byKey(42)->container('Photo')->delete();

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
- **ScriptInvoker** — FileMaker script invocation at database, entity-set,
  and record scope (by name or FMSID). Reuses spec-php script helpers.
- **ContainerRef** — container field download (binary), upload (binary or
  base64), and clear. MIME sniffing and Content-Disposition via spec-php.
- **HttpClient** — handles auth, OData headers, 401 retry, JSON/XML decoding,
  and error conversion. Uses a `TransportInterface` for the actual HTTP call.
- **CurlTransport** — the production transport, using PHP's built-in cURL
  extension. The only transport shipped.
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

## Roadmap

The following features are implemented in the JS and Python clients but not
yet ported to PHP:

- `$batch` multipart request composer
- Schema DDL (create/delete tables, fields, indexes)
- Webhook management (create, remove, get, invoke)

## Development

```bash
composer install
composer test        # PHPUnit (238 tests)
composer analyse     # PHPStan level max
composer check       # tests + static analysis
```

## License

MIT — see [LICENSE](LICENSE).
