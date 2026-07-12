<?php

declare(strict_types=1);

namespace FmsOData\Tests\Support;

use FmsOData\Http\Request;
use FmsOData\Http\Response;
use FmsOData\Http\TransportException;
use FmsOData\Http\TransportInterface;

final class MockTransport implements TransportInterface
{
    /** @var list<\Closure(Request): Response> */
    private array $handlers;

    private int $callIndex = 0;

    /** @var list<Request> */
    private array $capturedRequests = [];

    /**
     * @param \Closure(Request): Response|list<\Closure(Request): Response> $handler
     */
    public function __construct(\Closure|array $handler)
    {
        if ($handler instanceof \Closure) {
            $this->handlers = [$handler];
        } else {
            $this->handlers = $handler;
        }
    }

    public function send(Request $request): Response
    {
        $this->capturedRequests[] = $request;
        $index = \min($this->callIndex, \count($this->handlers) - 1);
        $handler = $this->handlers[$index];
        $this->callIndex++;

        return $handler($request);
    }

    /**
     * @return list<Request>
     */
    public function capturedRequests(): array
    {
        return $this->capturedRequests;
    }

    public function lastRequest(): ?Request
    {
        return $this->capturedRequests !== [] ? $this->capturedRequests[\count($this->capturedRequests) - 1] : null;
    }

    public static function throwingTransport(string $message): self
    {
        return new self(static fn (): Response => throw new TransportException($message));
    }
}
