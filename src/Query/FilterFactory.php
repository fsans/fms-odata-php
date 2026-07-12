<?php

declare(strict_types=1);

namespace FmsOData\Query;

use FmsOData\Spec\Query\QueryLiterals;

final class FilterFactory
{
    public function eq(string $field, string|int|float|bool|\DateTimeInterface|null $value): Filter
    {
        return new Filter($field . ' eq ' . $this->literal($value));
    }

    public function ne(string $field, string|int|float|bool|\DateTimeInterface|null $value): Filter
    {
        return new Filter($field . ' ne ' . $this->literal($value));
    }

    public function gt(string $field, string|int|float|bool|\DateTimeInterface|null $value): Filter
    {
        return new Filter($field . ' gt ' . $this->literal($value));
    }

    public function ge(string $field, string|int|float|bool|\DateTimeInterface|null $value): Filter
    {
        return new Filter($field . ' ge ' . $this->literal($value));
    }

    public function lt(string $field, string|int|float|bool|\DateTimeInterface|null $value): Filter
    {
        return new Filter($field . ' lt ' . $this->literal($value));
    }

    public function le(string $field, string|int|float|bool|\DateTimeInterface|null $value): Filter
    {
        return new Filter($field . ' le ' . $this->literal($value));
    }

    public function startsWith(string $field, string $value): Filter
    {
        return new Filter('startswith(' . $field . ',' . $this->literal($value) . ')');
    }

    public function endsWith(string $field, string $value): Filter
    {
        return new Filter('endswith(' . $field . ',' . $this->literal($value) . ')');
    }

    public function contains(string $field, string $value): Filter
    {
        return new Filter('contains(' . $field . ',' . $this->literal($value) . ')');
    }

    public function and(Filter|string $a, Filter|string $b): Filter
    {
        return new Filter('(' . Filter::coerce($a) . ') and (' . Filter::coerce($b) . ')');
    }

    public function or(Filter|string $a, Filter|string $b): Filter
    {
        return new Filter('(' . Filter::coerce($a) . ') or (' . Filter::coerce($b) . ')');
    }

    public function not(Filter|string $a): Filter
    {
        return new Filter('not (' . Filter::coerce($a) . ')');
    }

    public function raw(string $expression): Filter
    {
        return new Filter($expression);
    }

    private function literal(string|int|float|bool|\DateTimeInterface|null $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new \InvalidArgumentException('Non-finite float values are not supported in OData literals');
            }

            return (string) $value;
        }

        return QueryLiterals::formatLiteral($value);
    }
}
