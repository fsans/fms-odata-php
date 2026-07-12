<?php

declare(strict_types=1);

namespace FmsOData;

use FmsOData\Http\TransportInterface;

final class ClientOptions
{
    /** @var \Closure(): string|string */
    private \Closure|string $token;

    private ?\Closure $onUnauthorized;

    private ?TransportInterface $transport;

    private ?int $timeoutMs;

    private bool $verifySsl;

    public function __construct(
        public readonly string $host,
        public readonly string $database,
        \Closure|string $token,
        ?\Closure $onUnauthorized = null,
        ?TransportInterface $transport = null,
        ?int $timeoutMs = null,
        bool $verifySsl = true,
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('host must not be empty');
        }
        if ($database === '') {
            throw new \InvalidArgumentException('database must not be empty');
        }
        if ($token instanceof \Closure) {
            $this->token = $token;
        } else {
            if ($token === '') {
                throw new \InvalidArgumentException('token must not be empty');
            }
            $this->token = $token;
        }
        if ($timeoutMs !== null && $timeoutMs <= 0) {
            throw new \InvalidArgumentException('timeoutMs must be a positive integer');
        }
        $this->onUnauthorized = $onUnauthorized;
        $this->transport = $transport;
        $this->timeoutMs = $timeoutMs;
        $this->verifySsl = $verifySsl;
    }

    /**
     * @return \Closure(): string|string
     */
    public function token(): \Closure|string
    {
        return $this->token;
    }

    public function onUnauthorized(): ?\Closure
    {
        return $this->onUnauthorized;
    }

    public function transport(): ?TransportInterface
    {
        return $this->transport;
    }

    public function timeoutMs(): ?int
    {
        return $this->timeoutMs;
    }

    public function verifySsl(): bool
    {
        return $this->verifySsl;
    }

    public static function create(
        string $host,
        string $database,
        \Closure|string $token,
        ?\Closure $onUnauthorized = null,
        ?TransportInterface $transport = null,
        ?int $timeoutMs = null,
        bool $verifySsl = true,
    ): self {
        return new self(
            host: $host,
            database: $database,
            token: $token,
            onUnauthorized: $onUnauthorized,
            transport: $transport,
            timeoutMs: $timeoutMs,
            verifySsl: $verifySsl,
        );
    }
}
