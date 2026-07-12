<?php

declare(strict_types=1);

namespace FmsOData\Tests\Query;

use FmsOData\Http\HttpClient;
use FmsOData\Query\Query;
use FmsOData\Tests\Support\MockTransport;
use FmsOData\Tests\Support\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class QueryExecutionTest extends TestCase
{
    public function testCollectionEnvelopeParsing(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, [
            'value' => [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']],
        ]));
        $http = new HttpClient('Basic abc', $transport);
        $q = new Query($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts');
        $result = $q->get();
        self::assertCount(2, $result->value);
        $first = $result->value[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('name', $first);
        /** @var array<string, mixed> $firstArr */
        $firstArr = $first;
        self::assertSame('John', $firstArr['name']);
    }

    public function testDefaultEmptyValues(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, []));
        $http = new HttpClient('Basic abc', $transport);
        $q = new Query($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts');
        $result = $q->get();
        self::assertSame([], $result->value);
    }

    public function testCountExtracted(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, [
            '@odata.count' => 42,
            'value' => [['id' => 1]],
        ]));
        $http = new HttpClient('Basic abc', $transport);
        $q = new Query($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts');
        $result = $q->get();
        self::assertSame(42, $result->count);
    }

    public function testNextLinkExtracted(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, [
            'value' => [['id' => 1]],
            '@odata.nextLink' => 'https://fms.example.com/fmi/odata/v4/DB/Contacts?$skip=10',
        ]));
        $http = new HttpClient('Basic abc', $transport);
        $q = new Query($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts');
        $result = $q->get();
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts?$skip=10', $result->nextLink);
    }

    public function testCreateMethodUrlAndJsonBody(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(201, ['id' => 1, 'name' => 'John']));
        $http = new HttpClient('Basic abc', $transport);
        $q = new Query($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts');
        $result = $q->create(['name' => 'John']);
        self::assertSame(['id' => 1, 'name' => 'John'], $result);
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('POST', $req->method);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts', $req->url);
        self::assertSame('{"name":"John"}', $req->body);
    }

    public function testCreateContentTypeHeader(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(201, ['id' => 1]));
        $http = new HttpClient('Basic abc', $transport);
        $q = new Query($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts');
        $q->create(['name' => 'John']);
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('application/json', $req->headers['Content-Type']);
    }

    public function testCreateRepresentation(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(201, ['id' => 5, 'name' => 'Jane']));
        $http = new HttpClient('Basic abc', $transport);
        $q = new Query($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts');
        $result = $q->create(['name' => 'Jane']);
        self::assertIsArray($result);
        self::assertSame(5, $result['id']);
    }

    public function testCreateNoContentResult(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::noContent(204));
        $http = new HttpClient('Basic abc', $transport);
        $q = new Query($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts');
        $result = $q->create(['name' => 'Jane']);
        self::assertNull($result);
    }
}
