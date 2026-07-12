<?php

declare(strict_types=1);

namespace FmsOData\Query;

final class Filter
{
    public function __construct(
        private readonly string $expr,
    ) {}

    public function __toString(): string
    {
        return $this->expr;
    }

    public function and(Filter|string $other): Filter
    {
        return new Filter('(' . $this->expr . ') and (' . self::coerce($other) . ')');
    }

    public function or(Filter|string $other): Filter
    {
        return new Filter('(' . $this->expr . ') or (' . self::coerce($other) . ')');
    }

    public function not(): Filter
    {
        return new Filter('not (' . $this->expr . ')');
    }

    public static function coerce(Filter|string $input): string
    {
        return $input instanceof Filter ? $input->expr : $input;
    }
}
