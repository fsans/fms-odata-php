<?php

declare(strict_types=1);

namespace FmsOData;

use FmsOData\Spec\Query\QueryLiterals;

final class Url
{
    public static function encodePathSegment(string $s): string
    {
        return \rawurlencode($s);
    }

    public static function odataEncode(string $v): string
    {
        $encoded = \rawurlencode($v);

        return \str_replace(
            ['%2C', '%24', '%3D', '%3B', '%28', '%29', '%27'],
            [',', '$', '=', ';', '(', ')', "'"],
            $encoded,
        );
    }

    /**
     * @param array<int, array{string, string|int|float|bool|null}> $params
     */
    public static function buildQueryString(array $params): string
    {
        $parts = [];
        foreach ($params as [$key, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $key . '=' . self::odataEncode((string) $value);
        }

        return \implode('&', $parts);
    }

    public static function formatDateTime(\DateTimeInterface $d): string
    {
        $iso = $d->format('Y-m-d\TH:i:s');
        $tz = $d->getTimezone();
        $offset = $tz->getOffset($d);
        if ($offset === 0) {
            return $iso . 'Z';
        }

        $offsetHours = (int) ($offset / 3600);
        $offsetMinutes = (int) (\abs($offset) / 60 % 60);
        $sign = $offset >= 0 ? '+' : '-';

        return $iso . \sprintf('%s%02d:%02d', $sign, \abs($offsetHours), $offsetMinutes);
    }

    public static function parseDateTime(string $s): \DateTimeInterface
    {
        $normalized = $s;
        if (\str_ends_with($normalized, 'Z')) {
            $normalized = \substr($normalized, 0, -1) . '+00:00';
        }

        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $normalized);
        if ($dt === false) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $normalized);
        }
        if ($dt === false) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $normalized);
        }
        if ($dt === false) {
            throw new \InvalidArgumentException('Invalid date-time string: ' . $s);
        }

        return $dt;
    }

    public static function formatKey(string|int|bool $key): string
    {
        if (\is_bool($key)) {
            return $key ? 'true' : 'false';
        }
        if (\is_int($key)) {
            return (string) $key;
        }

        return "'" . QueryLiterals::escapeStringLiteral($key) . "'";
    }
}
