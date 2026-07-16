<?php

declare(strict_types=1);

namespace FmsOData\Query;

use FmsOData\Entity\EntityRef;
use FmsOData\Http\HttpClient;
use FmsOData\Http\HttpRequestOptions;
use FmsOData\Http\ResponseType;
use FmsOData\Scripts\ScriptInvoker;
use FmsOData\Spec\Query\QueryResult;
use FmsOData\Spec\Query\SortDirection;
use FmsOData\Spec\Scripts\ScriptResult;
use FmsOData\Url;

final class Query
{
    private HttpClient $http;

    private string $baseUrl;

    private string $entitySet;

    /** @var list<string> */
    private array $select = [];

    private ?string $filter = null;

    /** @var list<array{name: string, options: ?array<int, array{string, string|int|float|bool|null}>}> */
    private array $expand = [];

    /** @var list<array{field: string, dir: SortDirection}> */
    private array $orderby = [];

    private ?int $top = null;

    private ?int $skip = null;

    private ?bool $count = null;

    public function __construct(HttpClient $http, string $baseUrl, string $entitySet)
    {
        $this->http = $http;
        $this->baseUrl = $baseUrl;
        $this->entitySet = $entitySet;
    }

    public function select(string ...$fields): self
    {
        foreach ($fields as $field) {
            $this->select[] = $field;
        }

        return $this;
    }

    public function filter(Filter|string|\Closure $input): self
    {
        $expr = $this->resolveFilter($input);
        if ($this->filter === null) {
            $this->filter = $expr;
        } else {
            $this->filter = '(' . $this->filter . ') and (' . $expr . ')';
        }

        return $this;
    }

    public function orWhere(Filter|string|\Closure $input): self
    {
        $expr = $this->resolveFilter($input);
        if ($this->filter === null) {
            $this->filter = $expr;
        } else {
            $this->filter = '(' . $this->filter . ') or (' . $expr . ')';
        }

        return $this;
    }

    public function expand(string $name, ?\Closure $builder = null): self
    {
        $options = null;
        if ($builder !== null) {
            $nested = new self($this->http, $this->baseUrl, $name);
            $builder($nested);
            $options = $nested->buildParams();
        }
        $this->expand[] = ['name' => $name, 'options' => $options];

        return $this;
    }

    public function orderBy(string $field, SortDirection $direction = SortDirection::ASC): self
    {
        $this->orderby[] = ['field' => $field, 'dir' => $direction];

        return $this;
    }

    public function top(int $value): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('top() requires a non-negative integer');
        }
        $this->top = $value;

        return $this;
    }

    public function skip(int $value): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('skip() requires a non-negative integer');
        }
        $this->skip = $value;

        return $this;
    }

    public function count(bool $enabled = true): self
    {
        $this->count = $enabled;

        return $this;
    }

    public function toUrl(): string
    {
        $url = $this->baseUrl . '/' . Url::encodePathSegment($this->entitySet);
        $params = $this->buildParams();
        $qs = Url::buildQueryString($params);
        if ($qs !== '') {
            $url .= '?' . $qs;
        }

        return $url;
    }

    public function byKey(string|int|bool $key): EntityRef
    {
        return new EntityRef($this->http, $this->baseUrl, $this->entitySet, $key);
    }

    /**
     * Invoke a FileMaker script in the context of this query's entity set.
     *
     * For record-scope script execution use `EntityRef#script`.
     *
     * @param string|int|float|array<string, mixed>|null $parameter
     *
     * @see https://github.com/fsans/fms-odata-spec/blob/main/docs/06-scripts.md
     */
    public function script(string $name, string|int|float|array|null $parameter = null): ScriptResult
    {
        return (new ScriptInvoker($this->http, $this->baseUrl, $this->entitySet))->run($name, $parameter);
    }

    public function get(): QueryResult
    {
        $data = $this->http->sendJson($this->toUrl());

        $value = [];
        if (\is_array($data) && isset($data['value']) && \is_array($data['value'])) {
            $value = \array_values($data['value']);
        }

        $count = null;
        if (\is_array($data) && isset($data['@odata.count']) && \is_int($data['@odata.count'])) {
            $count = $data['@odata.count'];
        }

        $nextLink = null;
        if (\is_array($data) && isset($data['@odata.nextLink']) && \is_string($data['@odata.nextLink'])) {
            $nextLink = $data['@odata.nextLink'];
        }

        return new QueryResult($value, $count, $nextLink);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function create(array $body): mixed
    {
        $url = $this->baseUrl . '/' . Url::encodePathSegment($this->entitySet);
        $options = new HttpRequestOptions(
            method: 'POST',
            body: \json_encode($body, \JSON_THROW_ON_ERROR),
            responseType: ResponseType::JSON,
        );

        return $this->http->sendJson($url, $options);
    }

    private function resolveFilter(Filter|string|\Closure $input): string
    {
        if ($input instanceof \Closure) {
            $factory = new FilterFactory();
            $result = $factory->raw('');
            $result = $input($factory);
            if (!$result instanceof Filter && !\is_string($result)) {
                throw new \InvalidArgumentException('Filter callback must return a Filter or string');
            }

            return Filter::coerce($result);
        }

        return Filter::coerce($input);
    }

    /**
     * @return array<int, array{string, string|int|float|bool|null}>
     */
    private function buildParams(): array
    {
        $params = [];

        if ($this->select !== []) {
            $params[] = ['$select', \implode(',', $this->select)];
        }

        if ($this->filter !== null) {
            $params[] = ['$filter', $this->filter];
        }

        if ($this->expand !== []) {
            $parts = [];
            foreach ($this->expand as $exp) {
                $part = $exp['name'];
                if ($exp['options'] !== null && $exp['options'] !== []) {
                    $inner = [];
                    foreach ($exp['options'] as [$key, $value]) {
                        if ($value === null || $value === '') {
                            continue;
                        }
                        $inner[] = $key . '=' . (string) $value;
                    }
                    if ($inner !== []) {
                        $part .= '(' . \implode(';', $inner) . ')';
                    }
                }
                $parts[] = $part;
            }
            $params[] = ['$expand', \implode(',', $parts)];
        }

        if ($this->orderby !== []) {
            $parts = [];
            foreach ($this->orderby as $clause) {
                $parts[] = $clause['field'] . ($clause['dir'] === SortDirection::DESC ? ' desc' : '');
            }
            $params[] = ['$orderby', \implode(',', $parts)];
        }

        if ($this->top !== null) {
            $params[] = ['$top', $this->top];
        }

        if ($this->skip !== null) {
            $params[] = ['$skip', $this->skip];
        }

        if ($this->count !== null && $this->count) {
            $params[] = ['$count', 'true'];
        }

        return $params;
    }
}
