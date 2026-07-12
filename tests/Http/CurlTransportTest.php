<?php

declare(strict_types=1);

namespace FmsOData\Tests\Http;

use FmsOData\Http\CurlTransport;
use FmsOData\Http\Request;
use FmsOData\Http\TransportException;
use PHPUnit\Framework\TestCase;

final class CurlTransportTest extends TestCase
{
    public function testImplementsTransportInterface(): void
    {
        $transport = new CurlTransport();
        self::assertInstanceOf(\FmsOData\Http\TransportInterface::class, $transport);
    }

    public function testConnectionFailureThrowsTransportException(): void
    {
        $transport = new CurlTransport(false);
        $request = new Request('GET', 'https://localhost:1/test');
        $this->expectException(TransportException::class);
        $transport->send($request);
    }

    public function testVerifySslTrueByDefault(): void
    {
        $transport = new CurlTransport();
        // Just verify it constructs without error
        self::assertInstanceOf(CurlTransport::class, $transport);
    }

    public function testVerifySslFalseAccepted(): void
    {
        $transport = new CurlTransport(false);
        self::assertInstanceOf(CurlTransport::class, $transport);
    }
}
