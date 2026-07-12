<?php

declare(strict_types=1);

namespace FmsOData\Http;

final class HttpRequestOptions
{
    public string $method;

    /** @var array<string, string> */
    public array $headers;

    public ?string $body;

    public ResponseType $responseType;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        ResponseType $responseType = ResponseType::JSON,
    ) {
        $this->method = $method;
        $this->headers = $headers;
        $this->body = $body;
        $this->responseType = $responseType;
    }
}
