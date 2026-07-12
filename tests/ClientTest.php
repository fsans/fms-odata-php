<?php

declare(strict_types=1);

namespace FmsOData\Tests;

use FmsOData\Client;
use FmsOData\ClientOptions;
use FmsOData\FMSOData;
use FmsOData\Tests\Support\MockTransport;
use FmsOData\Tests\Support\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testHostSlashTrimming(): void
    {
        $client = Client::create(host: 'https://fms.example.com/', database: 'Contacts', token: 'Basic abc');
        self::assertSame('https://fms.example.com', $client->host);
    }

    public function testMultipleTrailingSlashesRemoved(): void
    {
        $client = Client::create(host: 'https://fms.example.com///', database: 'Contacts', token: 'Basic abc');
        self::assertSame('https://fms.example.com', $client->host);
    }

    public function testDatabasePathEncoding(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'My Database', token: 'Basic abc');
        self::assertSame('My%20Database', \rawurlencode('My Database'));
        self::assertSame('https://fms.example.com/fmi/odata/v4/My%20Database', $client->baseUrl);
    }

    public function testDatabaseWithSpecialChars(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'DB & Co.', token: 'Basic abc');
        $expected = 'https://fms.example.com/fmi/odata/v4/' . \rawurlencode('DB & Co.');
        self::assertSame($expected, $client->baseUrl);
    }

    public function testCorrectBaseUrlShape(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc');
        self::assertSame('https://fms.example.com/fmi/odata/v4/Contacts', $client->baseUrl);
    }

    public function testNoTrailingSlashInBaseUrl(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc');
        self::assertFalse(\str_ends_with($client->baseUrl, '/'));
    }

    public function testFromWithEmptyEntitySetRejected(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc');
        $this->expectException(\InvalidArgumentException::class);
        $client->from('');
    }

    public function testFromReturnsQuery(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'DB', token: 'Basic abc');
        $query = $client->from('Contacts');
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts', $query->toUrl());
    }

    public function testRelativeUrlResolution(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $client = Client::create(
            host: 'https://fms.example.com',
            database: 'DB',
            token: 'Basic abc',
            transport: $transport,
        );
        $result = $client->request('Contacts');
        self::assertSame(['ok' => true], $result);
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/Contacts', $req->url);
    }

    public function testAbsoluteUrlResolution(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $client = Client::create(
            host: 'https://fms.example.com',
            database: 'Contacts',
            token: 'Basic abc',
            transport: $transport,
        );
        $client->request('https://other.example.com/api/data');
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('https://other.example.com/api/data', $req->url);
    }

    public function testLeadingSlashUrlResolution(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $client = Client::create(
            host: 'https://fms.example.com',
            database: 'DB',
            token: 'Basic abc',
            transport: $transport,
        );
        $client->request('/custom/path');
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/custom/path', $req->url);
    }

    public function testEmptyPathRejected(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc');
        $this->expectException(\InvalidArgumentException::class);
        $client->request('');
    }

    public function testClientCreateFactory(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc');
        self::assertInstanceOf(Client::class, $client);
        self::assertSame('https://fms.example.com/fmi/odata/v4/Contacts', $client->baseUrl);
    }

    public function testFMSODataCreateReturnsAliasClass(): void
    {
        $client = FMSOData::create(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc');
        self::assertInstanceOf(FMSOData::class, $client);
        self::assertInstanceOf(Client::class, $client);
    }

    public function testFMSODataInheritsBaseUrl(): void
    {
        $client = FMSOData::create(host: 'https://fms.example.com/', database: 'My DB', token: 'Basic abc');
        self::assertSame('https://fms.example.com/fmi/odata/v4/My%20DB', $client->baseUrl);
    }

    public function testTimeoutMsExposedAsReadonly(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc', timeoutMs: 15000);
        self::assertSame(15000, $client->timeoutMs);
    }

    public function testTimeoutMsNullByDefault(): void
    {
        $client = Client::create(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc');
        self::assertNull($client->timeoutMs);
    }
}
