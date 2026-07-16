<?php

declare(strict_types=1);

namespace FmsOData\Tests\Containers;

use FmsOData\Client;
use FmsOData\ClientOptions;
use FmsOData\Containers\ContainerDownloadResult;
use FmsOData\Containers\ContainerRef;
use FmsOData\Http\Request;
use FmsOData\Spec\Containers\ContainerBinaryMimeType;
use FmsOData\Spec\Containers\ContainerEncoding;
use FmsOData\Spec\Containers\Containers;
use FmsOData\Tests\Support\MockTransport;
use FmsOData\Tests\Support\ResponseFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for container field I/O.
 *
 * Ports `tests/unit/containers.test.ts` (fms-odata-js) and
 * `tests/test_containers.py` (fms-odata-py) to PHP, exercising download,
 * binary and base64 upload, clear, MIME sniffing, and Content-Disposition
 * parsing.
 */
final class ContainerRefTest extends TestCase
{
    private const BASE = 'https://fms.example.com/fmi/odata/v4/DB';

    private const PNG_BYTES = "\x89\x50\x4e\x47\x0d\x0a\x1a\x0a\x00\x00\x00\x0d\x49\x48\x44\x52";

    private const JPEG_BYTES = "\xff\xd8\xff\xe0\x00\x10\x4a\x46\x49\x46";

    private const GIF_BYTES = 'GIF89a';

    private const TIFF_LE = "\x49\x49\x2a\x00";

    private const TIFF_BE = "\x4d\x4d\x00\x2a";

    private const PDF_BYTES = '%PDF-1.4';

    private function makeClient(MockTransport $transport): Client
    {
        return new Client(new ClientOptions(
            host: 'https://fms.example.com',
            database: 'DB',
            token: 'Basic abc',
            transport: $transport,
        ));
    }

    // -- parseContentDispositionFilename -----------------------------------

    public function testParseDispositionQuotedFilename(): void
    {
        self::assertSame('logo.png', ContainerRef::parseContentDispositionFilename('attachment; filename="logo.png"'));
    }

    public function testParseDispositionUnquotedFilename(): void
    {
        self::assertSame('logo.png', ContainerRef::parseContentDispositionFilename('attachment; filename=logo.png'));
    }

    public function testParseDispositionRfc5987FilenameStarPreferred(): void
    {
        // filename* takes precedence over filename; %C3%AF is UTF-8 for ï.
        $value = 'attachment; filename="ascii.png"; filename*=UTF-8\'\'na%C3%AFve%20file.png';
        self::assertSame("na\xC3\xAFve file.png", ContainerRef::parseContentDispositionFilename($value));
    }

    public function testParseDispositionNoFilenameReturnsNull(): void
    {
        self::assertNull(ContainerRef::parseContentDispositionFilename('attachment'));
    }

    // -- MIME sniffing (reused from spec-php) -------------------------------

    public function testSniffPng(): void
    {
        self::assertSame(ContainerBinaryMimeType::PNG, Containers::sniffMime(self::PNG_BYTES));
    }

    public function testSniffJpeg(): void
    {
        self::assertSame(ContainerBinaryMimeType::JPEG, Containers::sniffMime(self::JPEG_BYTES));
    }

    public function testSniffGif(): void
    {
        self::assertSame(ContainerBinaryMimeType::GIF, Containers::sniffMime(self::GIF_BYTES));
    }

    public function testSniffTiffLittleEndian(): void
    {
        self::assertSame(ContainerBinaryMimeType::TIFF, Containers::sniffMime(self::TIFF_LE));
    }

    public function testSniffTiffBigEndian(): void
    {
        self::assertSame(ContainerBinaryMimeType::TIFF, Containers::sniffMime(self::TIFF_BE));
    }

    public function testSniffPdf(): void
    {
        self::assertSame(ContainerBinaryMimeType::PDF, Containers::sniffMime(self::PDF_BYTES));
    }

    public function testSniffUnknownReturnsNull(): void
    {
        self::assertNull(Containers::sniffMime("\x00\x01\x02"));
    }

    // -- ContainerRef.url ---------------------------------------------------

    public function testContainerUrl(): void
    {
        $client = $this->makeClient(new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::noContent()));
        $ref = $client->from('Contacts')->byKey(7)->container('photo');
        self::assertSame(self::BASE . '/Contacts(7)/photo', $ref->url());
    }

    public function testContainerUrlEncodesFieldName(): void
    {
        $client = $this->makeClient(new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::noContent()));
        $ref = $client->from('Contacts')->byKey(7)->container('My Field');
        self::assertSame(self::BASE . '/Contacts(7)/My%20Field', $ref->url());
    }

    public function testEmptyFieldNameThrows(): void
    {
        $client = $this->makeClient(new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::noContent()));
        $this->expectException(\InvalidArgumentException::class);
        $client->from('Contacts')->byKey(7)->container('');
    }

    // -- ContainerRef.get ---------------------------------------------------

    public function testContainerGetReturnsBytes(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::binary(200, self::PNG_BYTES, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'attachment; filename="p.png"',
            ]);
        });
        $client = $this->makeClient($transport);

        $dl = $client->from('Contacts')->byKey(7)->container('photo')->get();

        self::assertInstanceOf(ContainerDownloadResult::class, $dl);
        self::assertNotNull($captured);
        self::assertSame(self::BASE . '/Contacts(7)/photo/$value', $captured->url);
        self::assertSame(self::PNG_BYTES, $dl->data);
        self::assertSame('image/png', $dl->contentType);
        self::assertSame(\strlen(self::PNG_BYTES), $dl->size);
        self::assertSame('p.png', $dl->filename);
    }

    public function testContainerGetStreamReturnsResponse(): void
    {
        $transport = new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::binary(200, self::PNG_BYTES, [
            'Content-Type' => 'image/png',
        ]));
        $client = $this->makeClient($transport);

        $response = $client->from('Contacts')->byKey(7)->container('photo')->getStream();

        self::assertSame(200, $response->status);
        self::assertSame(self::PNG_BYTES, $response->body);
    }

    // -- ContainerRef.upload (binary) --------------------------------------

    public function testUploadBinarySniffsMime(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::noContent();
        });
        $client = $this->makeClient($transport);

        $client->from('Contacts')->byKey(7)->container('photo')->upload(self::PNG_BYTES, filename: 'p.png');

        self::assertNotNull($captured);
        self::assertSame(self::BASE . '/Contacts(7)/photo', $captured->url);
        self::assertSame('PATCH', $captured->method);
        self::assertSame('image/png', $captured->headers['Content-Type']);
        self::assertSame(self::PNG_BYTES, $captured->body);
        self::assertSame('inline; filename=p.png', $captured->headers['Content-Disposition']);
    }

    public function testUploadBinaryExplicitContentType(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::noContent();
        });
        $client = $this->makeClient($transport);

        $client->from('Contacts')->byKey(7)->container('photo')->upload(self::JPEG_BYTES, contentType: 'image/jpeg');

        self::assertNotNull($captured);
        self::assertSame('image/jpeg', $captured->headers['Content-Type']);
        self::assertArrayNotHasKey('Content-Disposition', $captured->headers);
    }

    public function testUploadBinaryRejectsUnsupportedMime(): void
    {
        $client = $this->makeClient(new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::noContent()));

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageMatches('/not a FileMaker-supported/');
        $client->from('Contacts')->byKey(7)->container('photo')->upload("\x00\x01", contentType: 'application/zip');
    }

    public function testUploadCannotSniffThrows(): void
    {
        $client = $this->makeClient(new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::noContent()));

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageMatches('/could not be sniffed/');
        $client->from('Contacts')->byKey(7)->container('photo')->upload("\x00\x01\x02");
    }

    // -- ContainerRef.upload (base64) --------------------------------------

    public function testUploadBase64SendsJsonAnnotations(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::noContent();
        });
        $client = $this->makeClient($transport);

        $client->from('Contacts')->byKey(7)->container('photo')->upload(
            self::PNG_BYTES,
            contentType: 'image/png',
            filename: 'p.png',
            encoding: ContainerEncoding::BASE64,
        );

        self::assertNotNull($captured);
        self::assertSame(self::BASE . '/Contacts(7)', $captured->url);
        self::assertSame('application/json', $captured->headers['Content-Type']);
        $body = $this->decodeJsonBody((string) $captured->body);
        self::assertSame(\base64_encode(self::PNG_BYTES), $body['photo']);
        self::assertSame('image/png', $body['photo@com.filemaker.odata.ContentType']);
        self::assertSame('p.png', $body['photo@com.filemaker.odata.Filename']);
    }

    public function testUploadBase64OmitsFilenameAnnotationWhenAbsent(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::noContent();
        });
        $client = $this->makeClient($transport);

        $client->from('Contacts')->byKey(7)->container('photo')->upload(
            self::PNG_BYTES,
            contentType: 'image/png',
            encoding: ContainerEncoding::BASE64,
        );

        self::assertNotNull($captured);
        $body = $this->decodeJsonBody((string) $captured->body);
        self::assertArrayNotHasKey('photo@com.filemaker.odata.Filename', $body);
    }

    // -- ContainerRef.delete ------------------------------------------------

    public function testContainerDeleteClearsField(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::noContent();
        });
        $client = $this->makeClient($transport);

        $client->from('Contacts')->byKey(7)->container('photo')->delete();

        self::assertNotNull($captured);
        self::assertSame(self::BASE . '/Contacts(7)', $captured->url);
        self::assertSame('PATCH', $captured->method);
        $body = $this->decodeJsonBody((string) $captured->body);
        self::assertSame(['photo' => null], $body);
    }

    // -- EntityRef.patchContainers -----------------------------------------

    public function testPatchContainersBuildsAnnotatedJsonBody(): void
    {
        /** @var Request|null */
        $captured = null;
        $transport = new MockTransport(static function (Request $req) use (&$captured): \FmsOData\Http\Response {
            $captured = $req;

            return ResponseFactory::noContent();
        });
        $client = $this->makeClient($transport);

        $client->from('Contacts')->byKey(7)->patchContainers(
            [
                'photo' => ['data' => self::PNG_BYTES, 'contentType' => 'image/png', 'filename' => 'p.png'],
            ],
            ['name' => 'Jane'],
        );

        self::assertNotNull($captured);
        self::assertSame('PATCH', $captured->method);
        self::assertSame('application/json', $captured->headers['Content-Type']);
        $body = $this->decodeJsonBody((string) $captured->body);
        self::assertSame(\base64_encode(self::PNG_BYTES), $body['photo']);
        self::assertSame('image/png', $body['photo@com.filemaker.odata.ContentType']);
        self::assertSame('p.png', $body['photo@com.filemaker.odata.Filename']);
        self::assertSame('Jane', $body['name']);
    }

    public function testPatchContainersRequiresContentType(): void
    {
        $client = $this->makeClient(new MockTransport(static fn (): \FmsOData\Http\Response => ResponseFactory::noContent()));

        $this->expectException(\InvalidArgumentException::class);
        $client->from('Contacts')->byKey(7)->patchContainers(
            ['photo' => ['data' => self::PNG_BYTES, 'contentType' => '']],
        );
    }

    /**
     * @return array<mixed, mixed>
     */
    private function decodeJsonBody(string $json): array
    {
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
