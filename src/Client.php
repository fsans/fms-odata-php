<?php

declare(strict_types=1);

namespace FmsOData;

use FmsOData\Http\CurlTransport;
use FmsOData\Http\HttpClient;
use FmsOData\Http\HttpRequestOptions;
use FmsOData\Http\Response;
use FmsOData\Http\ResponseType;
use FmsOData\Http\TransportInterface;
use FmsOData\Metadata\MetadataFetcher;
use FmsOData\Metadata\MetadataOptions;
use FmsOData\Query\Query;
use FmsOData\Scripts\ScriptInvoker;
use FmsOData\Spec\Metadata\FMServerVersion;
use FmsOData\Spec\Scripts\ScriptResult;
use FmsOData\Spec\Metadata\Metadata;
use FmsOData\Spec\Metadata\ODataMetadata;
use FmsOData\Spec\Versions\FMVersionInfo;
use FmsOData\Spec\Versions\FMVersionMajor;
use FmsOData\Spec\Versions\Versions;

/** @phpstan-consistent-constructor */
class Client
{
    public readonly string $host;

    public readonly string $database;

    public readonly string $baseUrl;

    public readonly ?int $timeoutMs;

    protected HttpClient $http;

    protected MetadataFetcher $metadataFetcher;

    private ?FMServerVersion $cachedServerVersion = null;

    private bool $serverVersionResolved = false;

    private ?FMVersionMajor $cachedVersion = null;

    private bool $versionResolved = false;

    public function __construct(ClientOptions $options)
    {
        $this->host = \rtrim($options->host, '/');
        $this->database = $options->database;
        $this->baseUrl = $this->host . '/fmi/odata/v4/' . \rawurlencode($this->database);
        $this->timeoutMs = $options->timeoutMs();

        $transport = $options->transport();
        if ($transport === null) {
            $transport = new CurlTransport($options->verifySsl());
        }

        $this->http = new HttpClient(
            token: $options->token(),
            transport: $transport,
            onUnauthorized: $options->onUnauthorized(),
            timeoutMs: $options->timeoutMs(),
        );

        $this->metadataFetcher = new MetadataFetcher($this->http, $this->baseUrl);
    }

    public static function create(
        string $host,
        string $database,
        \Closure|string $token,
        ?\Closure $onUnauthorized = null,
        ?TransportInterface $transport = null,
        ?int $timeoutMs = null,
        bool $verifySsl = true,
    ): static {
        return new static(new ClientOptions(
            host: $host,
            database: $database,
            token: $token,
            onUnauthorized: $onUnauthorized,
            transport: $transport,
            timeoutMs: $timeoutMs,
            verifySsl: $verifySsl,
        ));
    }

    public function from(string $entitySet): Query
    {
        if ($entitySet === '') {
            throw new \InvalidArgumentException('entitySet must not be empty');
        }

        return new Query($this->http, $this->baseUrl, $entitySet);
    }

    /**
     * Invoke a FileMaker script at database scope.
     *
     * A non-zero `scriptError` is thrown as `FMScriptError`.
     *
     * @param string|int|float|array<string, mixed>|null $parameter
     *
     * @see https://github.com/fsans/fms-odata-spec/blob/main/docs/06-scripts.md
     */
    public function script(string $name, string|int|float|array|null $parameter = null): ScriptResult
    {
        return (new ScriptInvoker($this->http, $this->baseUrl))->run($name, $parameter);
    }

    /**
     * Invoke a FileMaker script by its immutable FMSID.
     *
     * Requires FileMaker Server 2026+ (v26). Use
     * `$client->hasFeature('scriptsByFMSID')` to check before calling.
     *
     * @param string|int|float|array<string, mixed>|null $parameter
     */
    public function scriptById(int $fmsid, string|int|float|array|null $parameter = null): ScriptResult
    {
        return (new ScriptInvoker($this->http, $this->baseUrl))->runById($fmsid, $parameter);
    }

    public function request(string $pathOrUrl, ?HttpRequestOptions $options = null): mixed
    {
        return $this->http->sendJson($this->resolveUrl($pathOrUrl), $options);
    }

    public function rawRequest(string $pathOrUrl, ?HttpRequestOptions $options = null): Response
    {
        return $this->http->sendRequest($this->resolveUrl($pathOrUrl), $options);
    }

    public function metadata(?MetadataOptions $options = null): ODataMetadata
    {
        return $this->metadataFetcher->fetch($options);
    }

    public function metadataXml(?MetadataOptions $options = null): string
    {
        return $this->metadataFetcher->fetchXml($options);
    }

    public function serverVersion(): ?FMServerVersion
    {
        if ($this->serverVersionResolved) {
            return $this->cachedServerVersion;
        }
        $this->serverVersionResolved = true;
        $xml = $this->metadataFetcher->fetchXml();
        $this->cachedServerVersion = Metadata::parseServerVersion($xml);

        return $this->cachedServerVersion;
    }

    public function version(): ?FMVersionMajor
    {
        if ($this->versionResolved) {
            return $this->cachedVersion;
        }
        $this->versionResolved = true;

        $serverVersion = $this->serverVersion();
        if ($serverVersion === null) {
            return null;
        }

        $this->cachedVersion = $this->mapMajorVersion($serverVersion->major);

        return $this->cachedVersion;
    }

    public function versionInfo(): ?FMVersionInfo
    {
        $version = $this->version();
        if ($version === null) {
            return null;
        }

        $matrix = Versions::matrix();

        return $matrix[$version->value] ?? null;
    }

    public function hasFeature(string $feature): bool
    {
        $version = $this->version();
        if ($version === null) {
            return false;
        }

        return Versions::hasFeature($version->value, $feature);
    }

    protected function resolveUrl(string $pathOrUrl): string
    {
        if ($pathOrUrl === '') {
            throw new \InvalidArgumentException('pathOrUrl must not be empty');
        }

        if (\str_starts_with($pathOrUrl, 'http://') || \str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        if (\str_starts_with($pathOrUrl, '/')) {
            return $this->baseUrl . $pathOrUrl;
        }

        return $this->baseUrl . '/' . $pathOrUrl;
    }

    private function mapMajorVersion(int $major): FMVersionMajor
    {
        return match ($major) {
            20 => FMVersionMajor::V20,
            21 => FMVersionMajor::V21,
            22 => FMVersionMajor::V22,
            26 => FMVersionMajor::V26,
            default => FMVersionMajor::FUTURE,
        };
    }
}
