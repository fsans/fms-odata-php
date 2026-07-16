<?php

declare(strict_types=1);

namespace FmsOData\Tests\Support;

use FmsOData\Http\Response;

final class ResponseFactory
{
    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, array|string $body, array $headers = []): Response
    {
        $content = \is_array($body) ? \json_encode($body, \JSON_THROW_ON_ERROR) : $body;
        $headers = \array_merge(['Content-Type' => 'application/json'], $headers);

        return new Response($status, self::normalizeHeaders($headers), $content);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function text(int $status, string $body, array $headers = []): Response
    {
        $headers = \array_merge(['Content-Type' => 'text/plain'], $headers);

        return new Response($status, self::normalizeHeaders($headers), $body);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function xml(int $status, string $body, array $headers = []): Response
    {
        $headers = \array_merge(['Content-Type' => 'application/xml'], $headers);

        return new Response($status, self::normalizeHeaders($headers), $body);
    }

    public static function noContent(int $status = 204): Response
    {
        return new Response($status, [], '');
    }

    /**
     * @param array<string, string> $headers
     */
    public static function binary(int $status, string $body, array $headers = []): Response
    {
        $headers = \array_merge(['Content-Type' => 'application/octet-stream'], $headers);

        return new Response($status, self::normalizeHeaders($headers), $body);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $body, string $contentType = 'application/json', array $headers = []): Response
    {
        $headers = \array_merge(['Content-Type' => $contentType], $headers);

        return new Response($status, self::normalizeHeaders($headers), $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[\strtolower($name)] = [$value];
        }

        return $normalized;
    }
}
