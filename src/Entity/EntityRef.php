<?php

declare(strict_types=1);

namespace FmsOData\Entity;

use FmsOData\Containers\ContainerRef;
use FmsOData\Http\HttpClient;
use FmsOData\Http\HttpRequestOptions;
use FmsOData\Http\ResponseType;
use FmsOData\Scripts\ScriptInvoker;
use FmsOData\Spec\Containers\ContainerEncoding;
use FmsOData\Spec\Containers\ContainerUploadInput;
use FmsOData\Spec\Containers\Containers;
use FmsOData\Spec\Scripts\ScriptResult;
use FmsOData\Url;

final class EntityRef
{
    private HttpClient $http;

    private string $baseUrl;

    private string $entitySet;

    private string|int|bool $key;

    public function __construct(HttpClient $http, string $baseUrl, string $entitySet, string|int|bool $key)
    {
        $this->http = $http;
        $this->baseUrl = $baseUrl;
        $this->entitySet = $entitySet;
        $this->key = $key;
    }

    public function toUrl(): string
    {
        return $this->baseUrl . '/' . Url::encodePathSegment($this->entitySet)
            . '(' . Url::formatKey($this->key) . ')';
    }

    public function get(): mixed
    {
        return $this->http->sendJson($this->toUrl());
    }

    public function fieldValue(string $fieldName): mixed
    {
        $url = $this->toUrl() . '/' . Url::encodePathSegment($fieldName);
        $data = $this->http->sendJson($url);

        if (!\is_array($data)) {
            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function patch(array $body, ?EntityWriteOptions $options = null): mixed
    {
        $options ??= new EntityWriteOptions();

        $headers = [];
        $headers['Prefer'] = $options->returnRepresentation ? 'return=representation' : 'return=minimal';
        if ($options->ifMatch !== null) {
            $headers['If-Match'] = $options->ifMatch;
        }

        $httpOptions = new HttpRequestOptions(
            method: 'PATCH',
            headers: $headers,
            body: \json_encode($body, \JSON_THROW_ON_ERROR),
            responseType: ResponseType::JSON,
        );

        return $this->http->sendJson($this->toUrl(), $httpOptions);
    }

    public function delete(?EntityWriteOptions $options = null): void
    {
        $options ??= new EntityWriteOptions();

        $headers = [];
        if ($options->ifMatch !== null) {
            $headers['If-Match'] = $options->ifMatch;
        }

        $httpOptions = new HttpRequestOptions(
            method: 'DELETE',
            headers: $headers,
            responseType: ResponseType::NONE,
        );

        $this->http->sendRequest($this->toUrl(), $httpOptions);
    }

    /**
     * Invoke a FileMaker script in the context of this single record. FMS
     * sets the script's current record to this entity before running it.
     *
     * @param string|int|float|array<string, mixed>|null $parameter
     *
     * @see https://github.com/fsans/fms-odata-spec/blob/main/docs/06-scripts.md
     */
    public function script(string $name, string|int|float|array|null $parameter = null): ScriptResult
    {
        return (new ScriptInvoker($this->http, $this->baseUrl, $this->entitySet, $this->key))->run($name, $parameter);
    }

    /**
     * Get a typed handle to one of this record's container fields, exposing
     * download, upload, and clear operations.
     *
     * @see https://github.com/fsans/fms-odata-spec/blob/main/docs/07-containers.md
     */
    public function container(string $fieldName): ContainerRef
    {
        return new ContainerRef($this->http, $this, $fieldName);
    }

    /**
     * Update one or more container fields (and optionally regular fields) on
     * this record in a single base64-encoded PATCH request.
     *
     * Each container value's `data` must already be base64-encoded (use the
     * spec-php `Containers::toBase64()` helper or pass raw bytes — this
     * method encodes raw bytes for you).
     *
     * @param array<string, array{data: string, contentType: string, filename?: string|null}> $containers
     * @param array<string, mixed>                                                            $regularFields
     *
     * @see https://github.com/fsans/fms-odata-spec/blob/main/docs/07-containers.md
     */
    public function patchContainers(array $containers, array $regularFields = []): mixed
    {
        $body = $regularFields;
        foreach ($containers as $field => $value) {
            if (!isset($value['contentType']) || $value['contentType'] === '') {
                throw new \InvalidArgumentException(
                    'EntityRef.patchContainers: "' . $field . '" requires a contentType.',
                );
            }
            $body[$field] = Containers::toBase64($value['data']);
            $body[$field . '@com.filemaker.odata.ContentType'] = $value['contentType'];
            if (isset($value['filename']) && \is_string($value['filename']) && $value['filename'] !== '') {
                $body[$field . '@com.filemaker.odata.Filename'] = $value['filename'];
            }
        }

        $httpOptions = new HttpRequestOptions(
            method: 'PATCH',
            headers: [
                'Content-Type' => 'application/json',
                'Prefer' => 'return=minimal',
            ],
            body: \json_encode($body, \JSON_THROW_ON_ERROR),
            responseType: ResponseType::NONE,
        );

        return $this->http->sendRequest($this->toUrl(), $httpOptions);
    }
}
