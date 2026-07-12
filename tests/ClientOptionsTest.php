<?php

declare(strict_types=1);

namespace FmsOData\Tests;

use FmsOData\ClientOptions;
use FmsOData\Http\TransportInterface;
use PHPUnit\Framework\TestCase;

final class ClientOptionsTest extends TestCase
{
    public function testMinimalValidOptions(): void
    {
        $opts = new ClientOptions(
            host: 'https://fms.example.com',
            database: 'Contacts',
            token: 'Basic abc123',
        );
        self::assertSame('https://fms.example.com', $opts->host);
        self::assertSame('Contacts', $opts->database);
        self::assertSame('Basic abc123', $opts->token());
        self::assertNull($opts->onUnauthorized());
        self::assertNull($opts->transport());
        self::assertNull($opts->timeoutMs());
        self::assertTrue($opts->verifySsl());
    }

    public function testEmptyHostRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientOptions(host: '', database: 'Contacts', token: 'Basic abc123');
    }

    public function testEmptyDatabaseRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientOptions(host: 'https://fms.example.com', database: '', token: 'Basic abc123');
    }

    public function testEmptyStaticTokenRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientOptions(host: 'https://fms.example.com', database: 'Contacts', token: '');
    }

    public function testZeroTimeoutRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientOptions(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc123', timeoutMs: 0);
    }

    public function testNegativeTimeoutRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientOptions(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc123', timeoutMs: -1);
    }

    public function testDefaultVerifySslIsTrue(): void
    {
        $opts = new ClientOptions(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc123');
        self::assertTrue($opts->verifySsl());
    }

    public function testVerifySslCanBeDisabled(): void
    {
        $opts = new ClientOptions(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc123', verifySsl: false);
        self::assertFalse($opts->verifySsl());
    }

    public function testCustomTransportAccepted(): void
    {
        $transport = $this->createStub(TransportInterface::class);
        $opts = new ClientOptions(host: 'https://fms.example.com', database: 'Contacts', token: 'Basic abc123', transport: $transport);
        self::assertSame($transport, $opts->transport());
    }

    public function testClosureTokenProviderAccepted(): void
    {
        $provider = static fn (): string => 'Bearer dynamic';
        $opts = new ClientOptions(host: 'https://fms.example.com', database: 'Contacts', token: $provider);
        self::assertSame($provider, $opts->token());
    }

    public function testNamedFactoryCreate(): void
    {
        $opts = ClientOptions::create(
            host: 'https://fms.example.com',
            database: 'Contacts',
            token: 'Basic abc123',
            timeoutMs: 5000,
            verifySsl: false,
        );
        self::assertSame('https://fms.example.com', $opts->host);
        self::assertSame(5000, $opts->timeoutMs());
        self::assertFalse($opts->verifySsl());
    }
}
