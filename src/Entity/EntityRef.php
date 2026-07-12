<?php

declare(strict_types=1);

namespace FmsOData\Entity;

use FmsOData\Http\HttpClient;
use FmsOData\Http\HttpRequestOptions;
use FmsOData\Http\ResponseType;
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
}
