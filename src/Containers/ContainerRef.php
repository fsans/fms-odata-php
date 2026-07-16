<?php

declare(strict_types=1);

namespace FmsOData\Containers;

use FmsOData\Entity\EntityRef;
use FmsOData\Http\HttpClient;
use FmsOData\Http\HttpRequestOptions;
use FmsOData\Http\Response;
use FmsOData\Http\ResponseType;
use FmsOData\Spec\Containers\ContainerBinaryMimeType;
use FmsOData\Spec\Containers\ContainerDownload;
use FmsOData\Spec\Containers\ContainerEncoding;
use FmsOData\Spec\Containers\ContainerUploadInput;
use FmsOData\Spec\Containers\Containers;
use FmsOData\Url;

/**
 * Handle to a single FileMaker container field on a specific record.
 *
 * Created via `EntityRef#container(fieldName)`.
 *
 * FMS exposes three container operations over OData v4:
 *
 *   GET   /<EntitySet>(<key>)/<field>/$value   -> ContainerRef#get
 *   PATCH /<EntitySet>(<key>)/<field>          -> ContainerRef#upload (binary)
 *   PATCH /<EntitySet>(<key>)  (JSON body)     -> ContainerRef#upload (base64)
 *
 * Supported binary MIME types: PNG, JPEG, GIF, TIFF, PDF.
 *
 * Mirrors `src/containers.ts` in fms-odata-js and
 * `src/fms_odata/containers.py` in fms-odata-py. Reuses the spec-php
 * {@see Containers} facade for MIME sniffing, Content-Disposition building,
 * and base64 encoding.
 *
 * PHP note: downloads return binary strings (PHP's native binary-safe
 * strings) rather than `Blob` (JS) or `bytes` (Python). For streaming
 * large payloads, use {@see getStream()} which yields a file resource
 * backed by the raw response body.
 *
 * @see https://github.com/fsans/fms-odata-spec/blob/main/docs/07-containers.md
 */
final class ContainerRef
{
    private HttpClient $http;

    private EntityRef $entity;

    private string $fieldName;

    public function __construct(HttpClient $http, EntityRef $entity, string $fieldName)
    {
        if ($fieldName === '') {
            throw new \InvalidArgumentException('ContainerRef: fieldName is required');
        }
        $this->http = $http;
        $this->entity = $entity;
        $this->fieldName = $fieldName;
    }

    /**
     * Absolute URL of the container field itself
     * (`.../<EntitySet>(<key>)/<fieldName>`). This is the URL used by binary
     * {@see upload()}. Append `/$value` to download.
     */
    public function url(): string
    {
        return $this->entity->toUrl() . '/' . Url::encodePathSegment($this->fieldName);
    }

    /**
     * Download the container's contents into memory as a binary string.
     *
     * Returns a {@see ContainerDownloadResult} (an extension of the spec-php
     * {@see ContainerDownload} with a `size` field for parity with the
     * JS/Python clients).
     *
     * FMS quirk: `Accept: application/octet-stream` makes `$value` return the
     * stored filename string as `text/plain` instead of the binary. This
     * method uses a wildcard Accept (ResponseType::NONE) so FMS returns the
     * actual bytes with a sniffed Content-Type.
     */
    public function get(): ContainerDownloadResult
    {
        $response = $this->http->sendRequest($this->valueUrl(), new HttpRequestOptions(
            method: 'GET',
            responseType: ResponseType::NONE,
        ));

        $contentType = $response->contentType() ?? '';
        $disposition = $response->header('Content-Disposition');
        $filename = $disposition !== null ? self::parseContentDispositionFilename($disposition) : null;

        return new ContainerDownloadResult(
            data: $response->body,
            contentType: $contentType,
            filename: $filename,
            size: \strlen($response->body),
        );
    }

    /**
     * Stream the container's contents without buffering the full body in
     * memory.
     *
     * Returns the raw {@see Response} so the caller can read `->body` in
     * chunks or write it to a file via `file_put_contents` / streams. For
     * most use cases {@see get()} is simpler; use this for very large
     * payloads.
     */
    public function getStream(): Response
    {
        return $this->http->sendRequest($this->valueUrl(), new HttpRequestOptions(
            method: 'GET',
            responseType: ResponseType::NONE,
        ));
    }

    /**
     * Upload contents to the container. Replaces any existing value.
     *
     * @param string                           $data         Binary payload (PHP binary string).
     * @param string|null                      $contentType  MIME type. When omitted, sniffed from magic bytes.
     * @param string|null                      $filename     Optional filename stored in the container.
     * @param ContainerEncoding|null           $encoding     BINARY (default) or BASE64.
     *
     * @throws \TypeError when binary upload is used with an unsupported MIME type.
     * @throws \TypeError when the MIME cannot be sniffed and none is provided.
     */
    public function upload(
        string $data,
        ?string $contentType = null,
        ?string $filename = null,
        ?ContainerEncoding $encoding = null,
    ): void {
        $encoding ??= ContainerEncoding::BINARY;

        if ($contentType === null) {
            $sniffed = Containers::sniffMime($data);
            if ($sniffed === null) {
                $supported = \implode(', ', \array_map(
                    static fn (ContainerBinaryMimeType $m): string => $m->value,
                    ContainerBinaryMimeType::all(),
                ));
                throw new \TypeError(
                    'ContainerRef.upload: contentType is required and could not be sniffed from the payload. '
                    . 'Pass a contentType explicitly (one of ' . $supported . ').',
                );
            }
            $contentType = $sniffed->value;
        }

        if ($encoding === ContainerEncoding::BINARY) {
            $this->uploadBinary($data, $contentType, $filename);

            return;
        }

        $this->uploadBase64($data, $contentType, $filename);
    }

    /**
     * Convenience wrapper accepting a {@see ContainerUploadInput} DTO,
     * matching the spec-php container upload input shape.
     */
    public function uploadInput(ContainerUploadInput $input): void
    {
        $this->upload(
            data: $input->data,
            contentType: $input->contentType,
            filename: $input->filename,
            encoding: $input->encoding,
        );
    }

    /**
     * Clear the container value. FMS has no documented per-field DELETE for
     * record-level data, so the supported path is to PATCH the record with
     * `{ <fieldName>: null }`.
     */
    public function delete(): void
    {
        $body = \json_encode([$this->fieldName => null], \JSON_THROW_ON_ERROR);

        $this->http->sendRequest($this->entity->toUrl(), new HttpRequestOptions(
            method: 'PATCH',
            headers: [
                'Content-Type' => 'application/json',
                'Prefer' => 'return=minimal',
            ],
            body: $body,
            responseType: ResponseType::NONE,
        ));
    }

    private function valueUrl(): string
    {
        return $this->url() . '/$value';
    }

    private function uploadBinary(string $data, string $contentType, ?string $filename): void
    {
        $normalized = self::normalizeMime($contentType);
        $supported = false;
        foreach (ContainerBinaryMimeType::all() as $mime) {
            if ($mime->value === $normalized) {
                $supported = true;
                break;
            }
        }

        if (!$supported) {
            $list = \implode(', ', \array_map(
                static fn (ContainerBinaryMimeType $m): string => $m->value,
                ContainerBinaryMimeType::all(),
            ));
            throw new \TypeError(
                'ContainerRef.upload (binary): contentType "' . $contentType . '" is not a FileMaker-supported '
                . 'container type. Use one of ' . $list . ', or switch to base64 encoding.',
            );
        }

        $headers = ['Content-Type' => $contentType];
        if ($filename !== null && $filename !== '') {
            $headers['Content-Disposition'] = Containers::buildContentDisposition($filename);
        }

        $this->http->sendRequest($this->url(), new HttpRequestOptions(
            method: 'PATCH',
            headers: $headers,
            body: $data,
            responseType: ResponseType::NONE,
        ));
    }

    private function uploadBase64(string $data, string $contentType, ?string $filename): void
    {
        $body = [
            $this->fieldName => Containers::toBase64($data),
            $this->fieldName . '@com.filemaker.odata.ContentType' => $contentType,
        ];
        if ($filename !== null && $filename !== '') {
            $body[$this->fieldName . '@com.filemaker.odata.Filename'] = $filename;
        }

        $this->http->sendRequest($this->entity->toUrl(), new HttpRequestOptions(
            method: 'PATCH',
            headers: [
                'Content-Type' => 'application/json',
                'Prefer' => 'return=minimal',
            ],
            body: \json_encode($body, \JSON_THROW_ON_ERROR),
            responseType: ResponseType::NONE,
        ));
    }

    private static function normalizeMime(string $value): string
    {
        $parts = \explode(';', $value, 2);

        return \trim(\strtolower($parts[0] ?? ''));
    }

    /**
     * Parse the `filename` (or RFC 5987 `filename*`) from a
     * `Content-Disposition` header. Returns null when no filename parameter
     * is present.
     *
     * Per RFC 6266 §4.3, `filename*` (with explicit charset) takes
     * precedence over `filename` when both are supplied.
     */
    public static function parseContentDispositionFilename(string $value): ?string
    {
        // RFC 5987: filename*=charset'lang'percent-encoded-value
        if (\preg_match("/filename\\*\\s*=\\s*([^']+)'([^']*)'([^;]+)/i", $value, $ext)) {
            $encoded = \trim($ext[3]);
            $decoded = \rawurldecode($encoded);

            return $decoded !== '' ? $decoded : null;
        }

        // Plain `filename="..."` or `filename=...` (unquoted, up to `;` or end).
        if (\preg_match('/filename\s*=\s*("([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|([^;]+))/i', $value, $plain)) {
            $quoted = $plain[2] ?? '';
            if ($quoted !== '') {
                $unescaped = \stripslashes($quoted);
                $trimmed = \trim($unescaped);

                return $trimmed !== '' ? $trimmed : null;
            }
            $unquoted = $plain[3] ?? '';
            if ($unquoted !== '') {
                $trimmed = \trim($unquoted);

                return $trimmed !== '' ? $trimmed : null;
            }
        }

        return null;
    }
}
