<?php

declare(strict_types=1);

namespace FmsOData\Metadata;

final class MetadataOptions
{
    public function __construct(
        public readonly bool $refresh = false,
    ) {}
}
