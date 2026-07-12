<?php

declare(strict_types=1);

namespace FmsOData\Tests\Metadata;

use FmsOData\Metadata\MetadataParser;
use FmsOData\Spec\Errors\FMODataError;
use PHPUnit\Framework\TestCase;

final class MetadataParserTest extends TestCase
{
    private string $metadataXml;

    protected function setUp(): void
    {
        $content = \file_get_contents(__DIR__ . '/../Fixtures/metadata.xml');
        $this->metadataXml = $content !== false ? $content : '';
    }

    public function testNamespace(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        self::assertSame('FMS', $meta->namespace);
    }

    public function testEntityTypes(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        self::assertCount(2, $meta->entityTypes);
        self::assertSame('Contact', $meta->entityTypes[0]->name);
        self::assertSame('Order', $meta->entityTypes[1]->name);
    }

    public function testKeys(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        $contact = $meta->entityTypes[0];
        self::assertSame(['id'], $contact->keys);
        $order = $meta->entityTypes[1];
        self::assertSame(['orderId'], $order->keys);
    }

    public function testPropertyKeyFlags(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        $contact = $meta->entityTypes[0];
        $idProp = self::findProperty($contact->properties, 'id');
        self::assertNotNull($idProp);
        self::assertTrue($idProp->isKey);
        $nameProp = self::findProperty($contact->properties, 'name');
        self::assertNotNull($nameProp);
        self::assertFalse($nameProp->isKey);
    }

    public function testPropertyTypes(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        $contact = $meta->entityTypes[0];
        $idProp = self::findProperty($contact->properties, 'id');
        self::assertNotNull($idProp);
        self::assertSame('Edm.Int32', $idProp->type);
        $nameProp = self::findProperty($contact->properties, 'name');
        self::assertNotNull($nameProp);
        self::assertSame('Edm.String', $nameProp->type);
    }

    public function testNullableValues(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        $contact = $meta->entityTypes[0];
        $idProp = self::findProperty($contact->properties, 'id');
        self::assertNotNull($idProp);
        self::assertFalse($idProp->nullable);
        $nameProp = self::findProperty($contact->properties, 'name');
        self::assertNotNull($nameProp);
        self::assertTrue($nameProp->nullable);
    }

    public function testMaxLength(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        $contact = $meta->entityTypes[0];
        $nameProp = self::findProperty($contact->properties, 'name');
        self::assertNotNull($nameProp);
        self::assertSame(100, $nameProp->maxLength);
    }

    public function testEntitySets(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        self::assertCount(2, $meta->entitySets);
        self::assertSame('Contacts', $meta->entitySets[0]->name);
        self::assertSame('FMS.Contact', $meta->entitySets[0]->entityType);
        self::assertSame('Orders', $meta->entitySets[1]->name);
    }

    public function testActions(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        self::assertCount(1, $meta->actions);
        self::assertSame('MyScript', $meta->actions[0]->name);
        self::assertTrue($meta->actions[0]->isBound);
    }

    public function testRawXmlPreserved(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        self::assertSame($this->metadataXml, $meta->raw);
    }

    public function testProductVersion(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        self::assertNotNull($meta->productVersion);
        self::assertStringContainsString('21.1.2', $meta->productVersion);
    }

    public function testServerVersion(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        self::assertNotNull($meta->serverVersion);
    }

    public function testMalformedXmlRejected(): void
    {
        $this->expectException(FMODataError::class);
        MetadataParser::parse('not xml at all');
    }

    public function testEmptyXmlRejected(): void
    {
        $this->expectException(FMODataError::class);
        MetadataParser::parse('');
    }

    public function testMissingOptionalSections(): void
    {
        $content = \file_get_contents(__DIR__ . '/../Fixtures/metadata-no-version.xml');
        $xml = $content !== false ? $content : '';
        $meta = MetadataParser::parse($xml);
        self::assertSame('FMS', $meta->namespace);
        self::assertCount(1, $meta->entityTypes);
        self::assertCount(1, $meta->entitySets);
        self::assertSame([], $meta->actions);
        self::assertNull($meta->productVersion);
    }

    public function testPrefixedElements(): void
    {
        $xml = '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="Test"><EntityType Name="Foo"><Key><PropertyRef Name="id"/></Key><Property Name="id" Type="Edm.Int32"/></EntityType></Schema></edmx:DataServices></edmx:Edmx>';
        $meta = MetadataParser::parse($xml);
        self::assertSame('Test', $meta->namespace);
        self::assertCount(1, $meta->entityTypes);
        self::assertSame('Foo', $meta->entityTypes[0]->name);
    }

    public function testSelfClosingElements(): void
    {
        $xml = '<?xml version="1.0"?><edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx"><edmx:DataServices><Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="Test"><EntityType Name="Foo"><Key><PropertyRef Name="id"/></Key><Property Name="id" Type="Edm.Int32"/></EntityType><EntityContainer Name="C"><EntitySet Name="Foos" EntityType="Test.Foo"/></EntityContainer></Schema></edmx:DataServices></edmx:Edmx>';
        $meta = MetadataParser::parse($xml);
        self::assertCount(1, $meta->entitySets);
        self::assertSame('Foos', $meta->entitySets[0]->name);
    }

    public function testEmptyEnumTypeList(): void
    {
        $meta = MetadataParser::parse($this->metadataXml);
        self::assertSame([], $meta->enumTypes);
    }

    /**
     * @param list<\FmsOData\Spec\Metadata\EdmProperty> $properties
     */
    private static function findProperty(array $properties, string $name): ?\FmsOData\Spec\Metadata\EdmProperty
    {
        foreach ($properties as $prop) {
            if ($prop->name === $name) {
                return $prop;
            }
        }

        return null;
    }
}
