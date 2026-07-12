<?php

declare(strict_types=1);

namespace FmsOData\Http;

final readonly class Response
{
    /**
     * @param array<string, list<string>> $headers Header name (lowercase) => list of values
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public ?string $reasonPhrase = null,
    ) {}

    public function header(string $name): ?string
    {
        $key = \strtolower($name);
        $values = $this->headers[$key] ?? null;

        return $values !== null && $values !== [] ? $values[0] : null;
    }

    public function contentType(): ?string
    {
        return $this->header('Content-Type');
    }

    public function isJson(): bool
    {
        $ctype = $this->contentType();

        return $ctype !== null && \str_contains(\strtolower($ctype), 'json');
    }

    public function isXml(): bool
    {
        $ctype = $this->contentType();

        return $ctype !== null && \str_contains(\strtolower($ctype), 'xml');
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
