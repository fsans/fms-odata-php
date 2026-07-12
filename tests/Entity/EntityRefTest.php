<?php

declare(strict_types=1);

namespace FmsOData\Tests\Entity;

use FmsOData\Entity\EntityRef;
use FmsOData\Entity\EntityWriteOptions;
use FmsOData\Http\HttpClient;
use FmsOData\Tests\Support\MockTransport;
use FmsOData\Tests\Support\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class EntityRefTest extends TestCase
{
    private function makeRef(string $entitySet, string|int|bool $key): EntityRef
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['id' => 1]));
        $http = new HttpClient('Basic abc', $transport);

        return new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', $entitySet, $key);
    }

    public function testIntegerKey(): void
    {
        $ref = $this->makeRef('Contacts', 42);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts(42)', $ref->toUrl());
    }

    public function testBooleanKey(): void
    {
        $ref = $this->makeRef('Contacts', true);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts(true)', $ref->toUrl());
    }

    public function testEscapedStringKey(): void
    {
        $ref = $this->makeRef('Contacts', "O'B-1");
        self::assertSame("https://fms.example.com/fmi/odata/v4/DB/Contacts('O''B-1')", $ref->toUrl());
    }

    public function testEncodedEntitySetName(): void
    {
        $ref = $this->makeRef('My Table', 1);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/My%20Table(1)', $ref->toUrl());
    }

    public function testGet(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['id' => 42, 'name' => 'John']));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $result = $ref->get();
        self::assertSame(['id' => 42, 'name' => 'John'], $result);
    }

    public function testFieldValueUnwrapping(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['value' => 'John']));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $result = $ref->fieldValue('name');
        self::assertSame('John', $result);
    }

    public function testFieldValueEncodedFieldName(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['value' => 'test']));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $ref->fieldValue('My Field');
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts(42)/My%20Field', $req->url);
    }

    public function testFieldValueNullWhenAbsent(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, []));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $result = $ref->fieldValue('name');
        self::assertNull($result);
    }

    public function testPatchMinimal(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::noContent(204));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $result = $ref->patch(['name' => 'Jane']);
        self::assertNull($result);
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('PATCH', $req->method);
        self::assertSame('return=minimal', $req->headers['Prefer']);
    }

    public function testPatchRepresentation(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['id' => 42, 'name' => 'Jane']));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $result = $ref->patch(['name' => 'Jane'], new EntityWriteOptions(returnRepresentation: true));
        self::assertSame(['id' => 42, 'name' => 'Jane'], $result);
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('return=representation', $req->headers['Prefer']);
    }

    public function testPatchIfMatch(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::noContent(204));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $ref->patch(['name' => 'Jane'], new EntityWriteOptions(ifMatch: 'W/"abc123"'));
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('W/"abc123"', $req->headers['If-Match']);
    }

    public function testPatchContentType(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::noContent(204));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $ref->patch(['name' => 'Jane']);
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('application/json', $req->headers['Content-Type']);
    }

    public function testDelete(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::noContent(204));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $ref->delete();
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('DELETE', $req->method);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts(42)', $req->url);
    }

    public function testDeleteIfMatch(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::noContent(204));
        $http = new HttpClient('Basic abc', $transport);
        $ref = new EntityRef($http, 'https://fms.example.com/fmi/odata/v4/DB', 'Contacts', 42);
        $ref->delete(new EntityWriteOptions(ifMatch: 'W/"abc123"'));
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('W/"abc123"', $req->headers['If-Match']);
    }
}
