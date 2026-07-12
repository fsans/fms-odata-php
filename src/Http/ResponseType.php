<?php

declare(strict_types=1);

namespace FmsOData\Http;

enum ResponseType: string
{
    case JSON = 'json';
    case XML = 'xml';
    case TEXT = 'text';
    case BINARY = 'binary';
    case NONE = 'none';

    public function defaultAccept(): string
    {
        return match ($this) {
            self::JSON => 'application/json',
            self::XML => 'application/xml',
            self::TEXT => 'text/plain',
            self::BINARY => 'application/octet-stream',
            self::NONE => '*/*',
        };
    }
}
