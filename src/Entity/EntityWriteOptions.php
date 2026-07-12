<?php

declare(strict_types=1);

namespace FmsOData\Entity;

final class EntityWriteOptions
{
    public function __construct(
        public readonly ?string $ifMatch = null,
        public readonly bool $returnRepresentation = false,
    ) {}
}
