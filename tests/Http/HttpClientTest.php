<?php

declare(strict_types=1);

namespace FmsOData\Tests\Http;

use FmsOData\Http\HttpClient;
use FmsOData\Http\HttpRequestOptions;
use FmsOData\Http\ResponseType;
use FmsOData\Http\TransportException;
use FmsOData\Spec\Auth\Auth;
use FmsOData\Spec\Errors\FMAuthError;
use FmsOData\Spec\Errors\FMNotFoundError;
use FmsOData\Spec\Errors\FMODataError;
use FmsOData\Spec\Errors\FMValidationError;
use FmsOData\Spec\Errors\RequestRef;
use FmsOData\Tests\Support\MockTransport;
use FmsOData\Tests\Support\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class HttpClientTest extends TestCase
{
    public function testBearerNormalization(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $http = new HttpClient('mytoken', $transport);
        $http->sendJson('https://example.com/test');
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('Bearer mytoken', $req->headers['Authorization']);
    }

    public function testBasicPassThrough(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $basic = Auth::basicAuth('admin', 'secret');
        $http = new HttpClient($basic, $transport);
        $http->sendJson('https://example.com/test');
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame($basic, $req->headers['Authorization']);
    }

    public function testTokenClosureInvocation(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $http = new HttpClient(static fn (): string => 'Bearer dynamic', $transport);
        $http->sendJson('https://example.com/test');
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('Bearer dynamic', $req->headers['Authorization']);
    }

    public function testEmptyResolvedToken(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $http = new HttpClient(static fn (): string => '', $transport);
        $this->expectException(FMODataError::class);
        $http->sendJson('https://example.com/test');
    }

    public function testRequiredODataHeaders(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $http = new HttpClient('Basic abc', $transport);
        $http->sendJson('https://example.com/test');
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('4.0', $req->headers['OData-Version']);
        self::assertSame('4.0', $req->headers['OData-MaxVersion']);
    }

    public function testAcceptMappingJson(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $http = new HttpClient('Basic abc', $transport);
        $http->sendJson('https://example.com/test');
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('application/json', $req->headers['Accept']);
    }

    public function testAcceptMappingXml(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::xml(200, '<root/>'));
        $http = new HttpClient('Basic abc', $transport);
        $http->sendRequest('https://example.com/test', new HttpRequestOptions(responseType: ResponseType::XML));
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('application/xml', $req->headers['Accept']);
    }

    public function testCallerHeadersOverrideDefaults(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $http = new HttpClient('Basic abc', $transport);
        $http->sendJson('https://example.com/test', new HttpRequestOptions(headers: ['Accept' => 'text/plain']));
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('text/plain', $req->headers['Accept']);
    }

    public function testCallerHeadersDoNotOverrideAuthorization(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ['ok' => true]));
        $http = new HttpClient('Basic abc', $transport);
        $http->sendJson('https://example.com/test', new HttpRequestOptions(headers: ['Authorization' => 'Bearer hijack']));
        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('Basic abc', $req->headers['Authorization']);
    }

    public function testOneTime401RefreshRetry(): void
    {
        $calls = 0;
        $transport = new MockTransport(static function () use (&$calls): \FmsOData\Http\Response {
            $calls++;
            if ($calls === 1) {
                return ResponseFactory::error(401, '{"error":{"code":"401","message":"Unauthorized"}}');
            }

            return ResponseFactory::json(200, ['ok' => true]);
        });
        $refreshed = false;
        $http = new HttpClient('Basic abc', $transport, onUnauthorized: static function () use (&$refreshed): void {
            $refreshed = true;
        });
        $result = $http->sendJson('https://example.com/test');
        self::assertTrue($refreshed);
        self::assertSame(['ok' => true], $result);
        self::assertSame(2, $calls);
    }

    public function testNoRetryWithoutCallback(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::error(401, '{"error":{"code":"401","message":"Unauthorized"}}'));
        $http = new HttpClient('Basic abc', $transport);
        $this->expectException(FMAuthError::class);
        $http->sendJson('https://example.com/test');
    }

    public function testNoThirdRequestAfterSecond401(): void
    {
        $calls = 0;
        $transport = new MockTransport(static function () use (&$calls): \FmsOData\Http\Response {
            $calls++;
            return ResponseFactory::error(401, '{"error":{"code":"401","message":"Unauthorized"}}');
        });
        $http = new HttpClient('Basic abc', $transport, onUnauthorized: static function (): void {});
        $this->expectException(FMAuthError::class);
        $http->sendJson('https://example.com/test');
        self::assertSame(2, $calls);
    }

    public function testTransportErrorMapping(): void
    {
        $transport = MockTransport::throwingTransport('Connection refused');
        $http = new HttpClient('Basic abc', $transport);
        try {
            $http->sendJson('https://example.com/test');
            self::fail('Expected FMODataError');
        } catch (FMODataError $e) {
            self::assertSame(0, $e->status);
            self::assertStringContainsString('Connection refused', $e->getMessage());
            self::assertNotNull($e->request);
            self::assertSame('https://example.com/test', $e->request->url);
        }
    }

    public function test204Handling(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::noContent(204));
        $http = new HttpClient('Basic abc', $transport);
        $result = $http->sendJson('https://example.com/test');
        self::assertNull($result);
    }

    public function testEmptyBodyHandling(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, ''));
        $http = new HttpClient('Basic abc', $transport);
        $result = $http->sendJson('https://example.com/test');
        self::assertNull($result);
    }

    public function testJsonWithoutContentType(): void
    {
        $transport = new MockTransport(static fn () => new \FmsOData\Http\Response(200, [], '{"ok":true}'));
        $http = new HttpClient('Basic abc', $transport);
        $result = $http->sendJson('https://example.com/test');
        self::assertSame(['ok' => true], $result);
    }

    public function testMalformedJson(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::json(200, '{invalid'));
        $http = new HttpClient('Basic abc', $transport);
        $this->expectException(FMODataError::class);
        $http->sendJson('https://example.com/test');
    }

    public function testJsonODataError(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::error(500, '{"error":{"code":"500","message":"Internal error"}}'));
        $http = new HttpClient('Basic abc', $transport);
        try {
            $http->sendJson('https://example.com/test');
            self::fail('Expected FMODataError');
        } catch (FMODataError $e) {
            self::assertSame(500, $e->status);
            self::assertSame('Internal error', $e->getMessage());
        }
    }

    public function testNestedMessageValue(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::error(400, '{"error":{"code":"400","message":{"value":"Bad request"}}}'));
        $http = new HttpClient('Basic abc', $transport);
        try {
            $http->sendJson('https://example.com/test');
            self::fail('Expected FMValidationError');
        } catch (FMValidationError $e) {
            self::assertSame('Bad request', $e->getMessage());
        }
    }

    public function testXmlFileMakerError(): void
    {
        $xml = '<?xml version="1.0"?><m:error><m:code>212</m:code><m:message>FileMaker error</m:message></m:error>';
        $transport = new MockTransport(static fn () => ResponseFactory::error(500, $xml, 'application/xml'));
        $http = new HttpClient('Basic abc', $transport);
        try {
            $http->sendJson('https://example.com/test');
            self::fail('Expected FMODataError');
        } catch (FMODataError $e) {
            self::assertSame('FileMaker error', $e->getMessage());
        }
    }

    public function testErrorBodyWithoutContentType(): void
    {
        $transport = new MockTransport(static fn () => new \FmsOData\Http\Response(500, [], '{"error":{"code":"500","message":"No ctype"}}'));
        $http = new HttpClient('Basic abc', $transport);
        try {
            $http->sendJson('https://example.com/test');
            self::fail('Expected FMODataError');
        } catch (FMODataError $e) {
            self::assertSame('No ctype', $e->getMessage());
        }
    }

    public function test400MapsToValidationError(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::error(400, '{"error":{"code":"400","message":"Bad"}}'));
        $http = new HttpClient('Basic abc', $transport);
        $this->expectException(FMValidationError::class);
        $http->sendJson('https://example.com/test');
    }

    public function test401MapsToAuthError(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::error(401, '{"error":{"code":"401","message":"Unauthorized"}}'));
        $http = new HttpClient('Basic abc', $transport);
        $this->expectException(FMAuthError::class);
        $http->sendJson('https://example.com/test');
    }

    public function test404MapsToNotFoundError(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::error(404, '{"error":{"code":"404","message":"Not found"}}'));
        $http = new HttpClient('Basic abc', $transport);
        $this->expectException(FMNotFoundError::class);
        $http->sendJson('https://example.com/test');
    }

    public function testMethodAndUrlInRequestRef(): void
    {
        $transport = new MockTransport(static fn () => ResponseFactory::error(500, '{"error":{"code":"500","message":"Err"}}'));
        $http = new HttpClient('Basic abc', $transport);
        try {
            $http->sendJson('https://example.com/test', new HttpRequestOptions(method: 'POST'));
            self::fail('Expected FMODataError');
        } catch (FMODataError $e) {
            self::assertNotNull($e->request);
            self::assertSame('POST', $e->request->method);
            self::assertSame('https://example.com/test', $e->request->url);
        }
    }

    public function testEmptyErrorBodyUsesReasonPhrase(): void
    {
        $transport = new MockTransport(static fn () => new \FmsOData\Http\Response(500, [], '', 'Internal Server Error'));
        $http = new HttpClient('Basic abc', $transport);
        try {
            $http->sendJson('https://example.com/test');
            self::fail('Expected FMODataError');
        } catch (FMODataError $e) {
            self::assertSame('Internal Server Error', $e->getMessage());
        }
    }

    public function testEmptyErrorBodyWithoutPhraseUsesStatus(): void
    {
        $transport = new MockTransport(static fn () => new \FmsOData\Http\Response(500, [], ''));
        $http = new HttpClient('Basic abc', $transport);
        try {
            $http->sendJson('https://example.com/test');
            self::fail('Expected FMODataError');
        } catch (FMODataError $e) {
            self::assertSame('HTTP 500', $e->getMessage());
        }
    }
}
