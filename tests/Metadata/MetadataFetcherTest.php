<?php

declare(strict_types=1);

namespace FmsOData\Tests\Metadata;

use FmsOData\Http\HttpClient;
use FmsOData\Http\HttpRequestOptions;
use FmsOData\Http\ResponseType;
use FmsOData\Metadata\MetadataFetcher;
use FmsOData\Metadata\MetadataOptions;
use FmsOData\Tests\Support\MockTransport;
use FmsOData\Tests\Support\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class MetadataFetcherTest extends TestCase
{
    private string $metadataXml;

    protected function setUp(): void
    {
        $content = \file_get_contents(__DIR__ . '/../Fixtures/metadata.xml');
        $this->metadataXml = $content !== false ? $content : '';
    }

    public function testMetadataEndpointUrl(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::xml(200, '<root/>'));
        $http = new HttpClient('Basic abc', $transport);
        $fetcher = new MetadataFetcher($http, 'https://fms.example.com/fmi/odata/v4/DB');
        $fetcher->fetchXml();
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('https://fms.example.com/fmi/odata/v4/DB/$metadata', $req->url);
    }

    public function testXmlAcceptHeader(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::xml(200, '<root/>'));
        $http = new HttpClient('Basic abc', $transport);
        $fetcher = new MetadataFetcher($http, 'https://fms.example.com/fmi/odata/v4/DB');
        $fetcher->fetchXml();
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('application/xml', $req->headers['Accept']);
    }

    public function testParsedCacheReturnedOnSecondCall(): void
    {
        $xml = $this->metadataXml;
        $calls = 0;
        $transport = new MockTransport(function () use (&$calls, $xml): \FmsOData\Http\Response {
            $calls++;
            return ResponseFactory::xml(200, $xml);
        });
        $http = new HttpClient('Basic abc', $transport);
        $fetcher = new MetadataFetcher($http, 'https://fms.example.com/fmi/odata/v4/DB');
        $fetcher->fetch();
        $fetcher->fetch();
        self::assertSame(1, $calls);
    }

    public function testRawCacheReturnedOnSecondXmlCall(): void
    {
        $xml = $this->metadataXml;
        $calls = 0;
        $transport = new MockTransport(function () use (&$calls, $xml): \FmsOData\Http\Response {
            $calls++;
            return ResponseFactory::xml(200, $xml);
        });
        $http = new HttpClient('Basic abc', $transport);
        $fetcher = new MetadataFetcher($http, 'https://fms.example.com/fmi/odata/v4/DB');
        $fetcher->fetchXml();
        $fetcher->fetchXml();
        self::assertSame(1, $calls);
    }

    public function testParsingFromRawCacheWithoutRefetch(): void
    {
        $xml = $this->metadataXml;
        $calls = 0;
        $transport = new MockTransport(function () use (&$calls, $xml): \FmsOData\Http\Response {
            $calls++;
            return ResponseFactory::xml(200, $xml);
        });
        $http = new HttpClient('Basic abc', $transport);
        $fetcher = new MetadataFetcher($http, 'https://fms.example.com/fmi/odata/v4/DB');
        $fetcher->fetchXml();
        $calls = 0;
        $fetcher->fetch();
        self::assertSame(0, $calls);
    }

    public function testRefreshBypassesCache(): void
    {
        $xml = $this->metadataXml;
        $calls = 0;
        $transport = new MockTransport(function () use (&$calls, $xml): \FmsOData\Http\Response {
            $calls++;
            return ResponseFactory::xml(200, $xml);
        });
        $http = new HttpClient('Basic abc', $transport);
        $fetcher = new MetadataFetcher($http, 'https://fms.example.com/fmi/odata/v4/DB');
        $fetcher->fetch();
        $fetcher->fetch(new MetadataOptions(refresh: true));
        self::assertSame(2, $calls);
    }
}
