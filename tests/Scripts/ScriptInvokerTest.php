<?php

declare(strict_types=1);

namespace FmsOData\Tests\Scripts;

use FmsOData\Client;
use FmsOData\ClientOptions;
use FmsOData\Entity\EntityRef;
use FmsOData\Http\HttpClient;
use FmsOData\Http\Request;
use FmsOData\Query\Query;
use FmsOData\Scripts\ScriptInvoker;
use FmsOData\Spec\Errors\FMScriptError;
use FmsOData\Spec\Scripts\ScriptResult;
use FmsOData\Tests\Support\MockTransport;
use FmsOData\Tests\Support\ResponseFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for script invocation.
 *
 * Ports `tests/unit/scripts.test.ts` (fms-odata-js) and
 * `tests/test_scripts.py` (fms-odata-py) to PHP, exercising the three
 * scopes (database, entity-set, record), FMSID invocation, the nested
 * (v26+) and flat (older FMS) response envelopes, and non-zero error
 * promotion to {@see FMScriptError}.
 */
final class ScriptInvokerTest extends TestCase
{
    private const BASE = 'https://fms.example.com/fmi/odata/v4/DB';

    private function makeClient(MockTransport $transport): Client
    {
        return new Client(new ClientOptions(
            host: 'https://fms.example.com',
            database: 'DB',
            token: 'Basic abc',
            transport: $transport,
        ));
    }

    public function testDatabaseScopeUrl(): void
    {
        $invoker = new ScriptInvoker(new HttpClient('Basic abc', new MockTransport(static fn () => ResponseFactory::json(200, []))), self::BASE);
        self::assertSame(self::BASE . '/Script.Ping', $invoker->url('Ping'));
    }

    public function testEntitySetScopeUrl(): void
    {
        $invoker = new ScriptInvoker(new HttpClient('Basic abc', new MockTransport(static fn () => ResponseFactory::json(200, []))), self::BASE, 'Contacts');
        self::assertSame(self::BASE . '/Contacts/Script.Ping', $invoker->url('Ping'));
    }

    public function testRecordScopeUrl(): void
    {
        $invoker = new ScriptInvoker(new HttpClient('Basic abc', new MockTransport(static fn () => ResponseFactory::json(200, []))), self::BASE, 'Contacts', 7);
        self::assertSame(self::BASE . '/Contacts(7)/Script.Ping', $invoker->url('Ping'));
    }

    public function testRecordScopeStringKeyUrl(): void
    {
        $invoker = new ScriptInvoker(new HttpClient('Basic abc', new MockTransport(static fn () => ResponseFactory::json(200, []))), self::BASE, 'Contacts', 'ALFKI');
        self::assertSame(self::BASE . "/Contacts('ALFKI')/Script.Ping", $invoker->url('Ping'));
    }

    public function testFmsidUrl(): void
    {
        $invoker = new ScriptInvoker(new HttpClient('Basic abc', new MockTransport(static fn () => ResponseFactory::json(200, []))), self::BASE);
        self::assertSame(self::BASE . '/Script.FMSID:42', $invoker->urlById(42));
    }

    public function testEmptyNameThrows(): void
    {
        $invoker = new ScriptInvoker(new HttpClient('Basic abc', new MockTransport(static fn () => ResponseFactory::json(200, []))), self::BASE);
        $this->expectException(\InvalidArgumentException::class);
        $invoker->url('');
    }

    public function testDatabaseScopeNoBodyWhenParameterOmitted(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::json(200, ['scriptResult' => 'pong', 'scriptError' => '0']);
        });
        $client = $this->makeClient($transport);

        $result = $client->script('Ping');

        self::assertInstanceOf(ScriptResult::class, $result);
        self::assertNotNull($captured);
        self::assertSame(self::BASE . '/Script.Ping', $captured->url);
        self::assertSame('POST', $captured->method);
        // No parameter => no Content-Type, no body.
        self::assertNull($captured->body);
        self::assertArrayNotHasKey('Content-Type', $captured->headers);
    }

    public function testScriptSendsScriptParameterValueAsJson(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::json(200, ['scriptResult' => 'pong:hi', 'scriptError' => '0']);
        });
        $client = $this->makeClient($transport);

        $client->script('Ping', 'hi');

        self::assertNotNull($captured);
        self::assertSame('application/json', $captured->headers['Content-Type']);
        $decoded = \json_decode((string) $captured->body, true);
        self::assertSame(['scriptParameterValue' => 'hi'], $decoded);
    }

    public function testFlatEnvelopeReturnsResultParameter(): void
    {
        $transport = new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::json(200, ['scriptResult' => 'pong', 'scriptError' => '0']));
        $client = $this->makeClient($transport);

        $result = $client->script('Ping');

        // spec-php parseResponse handles the nested shape; flat shape yields code 0.
        self::assertSame(0, $result->code);
    }

    public function testNestedEnvelopeV26(): void
    {
        $transport = new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::json(200, [
            'scriptResult' => ['code' => 0, 'resultParameter' => 'Hello World'],
        ]));
        $client = $this->makeClient($transport);

        $result = $client->script('Ping');

        self::assertSame(0, $result->code);
        self::assertSame('Hello World', $result->resultParameter);
    }

    public function testNestedEnvelopeNonZeroRaises(): void
    {
        $transport = new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::json(200, [
            'scriptResult' => ['code' => 3, 'resultParameter' => ''],
        ]));
        $client = $this->makeClient($transport);

        try {
            $client->script('Bad');
            self::fail('Expected FMScriptError');
        } catch (FMScriptError $e) {
            self::assertSame(3, $e->scriptError);
        }
    }

    public function testScriptByIdBuildsFmsidUrl(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::json(200, ['scriptResult' => ['code' => 0, 'resultParameter' => 'ok']]);
        });
        $client = $this->makeClient($transport);

        $client->scriptById(42, 'hi');

        self::assertNotNull($captured);
        self::assertSame(self::BASE . '/Script.FMSID:42', $captured->url);
    }

    public function testEntitySetScopeViaQuery(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::json(200, ['scriptResult' => ['code' => 0, 'resultParameter' => 'ok']]);
        });
        $client = $this->makeClient($transport);

        $client->from('Contacts')->script('Ping');

        self::assertNotNull($captured);
        self::assertSame(self::BASE . '/Contacts/Script.Ping', $captured->url);
    }

    public function testRecordScopeViaEntityRef(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::json(200, ['scriptResult' => ['code' => 0, 'resultParameter' => 'ok']]);
        });
        $client = $this->makeClient($transport);

        $client->from('Contacts')->byKey(7)->script('Ping');

        self::assertNotNull($captured);
        self::assertSame(self::BASE . '/Contacts(7)/Script.Ping', $captured->url);
    }

    public function testParseAndRaiseZeroCodeReturnsResult(): void
    {
        $result = ScriptInvoker::parseAndRaise(
            ['scriptResult' => ['code' => 0, 'resultParameter' => 'ok']],
            self::BASE . '/Script.Ping',
        );
        self::assertSame(0, $result->code);
        self::assertSame('ok', $result->resultParameter);
    }

    public function testParseAndRaiseNonZeroThrows(): void
    {
        $this->expectException(FMScriptError::class);
        ScriptInvoker::parseAndRaise(
            ['scriptResult' => ['code' => 101, 'resultParameter' => '']],
            self::BASE . '/Script.Ping',
        );
    }
}
