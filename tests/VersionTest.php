<?php

declare(strict_types=1);

namespace FmsOData\Tests;

use FmsOData\Client;
use FmsOData\Tests\Support\MockTransport;
use FmsOData\Tests\Support\ResponseFactory;
use FmsOData\Spec\Versions\FMVersionMajor;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    private function makeClientWithMetadata(string $xml): Client
    {
        $transport = new MockTransport(static fn () => ResponseFactory::xml(200, $xml));

        return Client::create(
            host: 'https://fms.example.com',
            database: 'DB',
            token: 'Basic abc',
            transport: $transport,
        );
    }

    private function metadataWithVersion(string $version): string
    {
        return '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="FMS"><Annotation Term="Org.OData.Core.V1.ProductVersion" String="' . $version . '"/></Schema></edmx:DataServices></edmx:Edmx>';
    }

    public function testVersion20(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('20.0.1.100'));
        self::assertSame(FMVersionMajor::V20, $client->version());
    }

    public function testVersion21(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('21.1.2.500'));
        self::assertSame(FMVersionMajor::V21, $client->version());
    }

    public function testVersion22(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('22.0.1.100'));
        self::assertSame(FMVersionMajor::V22, $client->version());
    }

    public function testVersion26(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('26.0.1.100'));
        self::assertSame(FMVersionMajor::V26, $client->version());
    }

    public function testFutureVersion(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('99.0.1.100'));
        self::assertSame(FMVersionMajor::FUTURE, $client->version());
    }

    public function testAbsentVersionAnnotation(): void
    {
        $xml = '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="FMS"><EntityType Name="Foo"><Key><PropertyRef Name="id"/></Key><Property Name="id" Type="Edm.Int32"/></EntityType></Schema></edmx:DataServices></edmx:Edmx>';
        $client = $this->makeClientWithMetadata($xml);
        self::assertNull($client->version());
    }

    public function testReversedAttributeOrder(): void
    {
        $xml = '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="FMS"><Annotation String="21.1.2.500" Term="Org.OData.Core.V1.ProductVersion"/></Schema></edmx:DataServices></edmx:Edmx>';
        $client = $this->makeClientWithMetadata($xml);
        self::assertSame(FMVersionMajor::V21, $client->version());
    }

    public function testServerVersionAnnotation(): void
    {
        $xml = '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="FMS"><Annotation Term="ServerVersion" String="OData Engine 26.0.1"/></Schema></edmx:DataServices></edmx:Edmx>';
        $client = $this->makeClientWithMetadata($xml);
        self::assertSame(FMVersionMajor::V26, $client->version());
    }

    public function testBuildSuffix(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('21.1.2.500'));
        $version = $client->serverVersion();
        self::assertNotNull($version);
        self::assertSame(21, $version->major);
        self::assertSame(1, $version->minor);
        self::assertSame(2, $version->patch);
        self::assertSame('21.1.2.500', $version->raw);
    }

    public function testFullServerVersion(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('22.3.7.100'));
        $version = $client->serverVersion();
        self::assertNotNull($version);
        self::assertSame(22, $version->major);
        self::assertSame(3, $version->minor);
        self::assertSame(7, $version->patch);
    }

    public function testNullCaching(): void
    {
        $xml = '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="FMS"><EntityType Name="Foo"><Key><PropertyRef Name="id"/></Key><Property Name="id" Type="Edm.Int32"/></EntityType></Schema></edmx:DataServices></edmx:Edmx>';
        $calls = 0;
        $transport = new MockTransport(static function () use (&$calls, $xml): \FmsOData\Http\Response {
            $calls++;
            return ResponseFactory::xml(200, $xml);
        });
        $client = Client::create(
            host: 'https://fms.example.com',
            database: 'DB',
            token: 'Basic abc',
            transport: $transport,
        );
        self::assertNull($client->version());
        self::assertNull($client->version());
        self::assertSame(1, $calls);
    }

    public function testVersionInfo(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('21.1.2.500'));
        $info = $client->versionInfo();
        self::assertNotNull($info);
        self::assertSame(FMVersionMajor::V21, $info->major);
        self::assertSame('Claris FileMaker 2024', $info->name);
    }

    public function testVersionInfoNullWhenUndetected(): void
    {
        $xml = '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="FMS"><EntityType Name="Foo"><Key><PropertyRef Name="id"/></Key><Property Name="id" Type="Edm.Int32"/></EntityType></Schema></edmx:DataServices></edmx:Edmx>';
        $client = $this->makeClientWithMetadata($xml);
        self::assertNull($client->versionInfo());
    }

    public function testSupportedFeature(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('22.0.1.100'));
        self::assertTrue($client->hasFeature('webhooks'));
    }

    public function testUnsupportedFeature(): void
    {
        $client = $this->makeClientWithMetadata($this->metadataWithVersion('20.0.1.100'));
        self::assertFalse($client->hasFeature('webhooks'));
    }

    public function testUndetectedFeatureReturnsFalse(): void
    {
        $xml = '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="FMS"><EntityType Name="Foo"><Key><PropertyRef Name="id"/></Key><Property Name="id" Type="Edm.Int32"/></EntityType></Schema></edmx:DataServices></edmx:Edmx>';
        $client = $this->makeClientWithMetadata($xml);
        self::assertFalse($client->hasFeature('webhooks'));
    }
}
