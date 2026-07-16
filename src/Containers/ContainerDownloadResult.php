<?php

declare(strict_types=1);

namespace FmsOData\Containers;

/**
 * Result of a successful container download.
 *
 * Mirrors the JS (`ContainerDownload.size`) and Python
 * (`ContainerDownload.size`) client result shapes. The spec-php
 * {@see \FmsOData\Spec\Containers\ContainerDownload} is `final` and lacks
 * a `size` field, so this client-side DTO carries the same fields plus
 * `size` for parity across the three language clients.
 */
final readonly class ContainerDownloadResult
{
    public function __construct(
        public string $data,
        public string $contentType,
        public ?string $filename = null,
        public int $size = 0,
    ) {}
}
