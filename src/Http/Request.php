<?php

declare(strict_types=1);

namespace FmsOData\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
        public ?int $timeoutMs = null,
    ) {}
}
