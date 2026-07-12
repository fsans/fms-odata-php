<?php

declare(strict_types=1);

namespace FmsOData\Metadata;

use FmsOData\Http\HttpClient;
use FmsOData\Http\HttpRequestOptions;
use FmsOData\Http\ResponseType;
use FmsOData\Spec\Metadata\ODataMetadata;

final class MetadataFetcher
{
    private HttpClient $http;

    private string $baseUrl;

    private ?ODataMetadata $parsedCache = null;

    private ?string $rawCache = null;

    public function __construct(HttpClient $http, string $baseUrl)
    {
        $this->http = $http;
        $this->baseUrl = $baseUrl;
    }

    public function fetch(?MetadataOptions $options = null): ODataMetadata
    {
        $options ??= new MetadataOptions();

        if (!$options->refresh && $this->parsedCache !== null) {
            return $this->parsedCache;
        }

        if (!$options->refresh && $this->rawCache !== null) {
            $this->parsedCache = MetadataParser::parse($this->rawCache);

            return $this->parsedCache;
        }

        $xml = $this->fetchXml($options);
        $this->rawCache = $xml;
        $this->parsedCache = MetadataParser::parse($xml);

        return $this->parsedCache;
    }

    public function fetchXml(?MetadataOptions $options = null): string
    {
        $options ??= new MetadataOptions();

        if (!$options->refresh && $this->rawCache !== null) {
            return $this->rawCache;
        }

        $url = $this->baseUrl . '/$metadata';
        $httpOptions = new HttpRequestOptions(
            method: 'GET',
            responseType: ResponseType::XML,
        );

        $response = $this->http->sendRequest($url, $httpOptions);
        $this->rawCache = $response->body;

        return $this->rawCache;
    }
}
