<?php

declare(strict_types=1);

namespace FmsOData\Http;

use FmsOData\Spec\Auth\Auth;
use FmsOData\Spec\Errors\FMAuthError;
use FmsOData\Spec\Errors\FMNotFoundError;
use FmsOData\Spec\Errors\FMODataError;
use FmsOData\Spec\Errors\FMValidationError;
use FmsOData\Spec\Errors\ODataErrorBody;
use FmsOData\Spec\Errors\ODataErrorDetail;
use FmsOData\Spec\Errors\ODataErrorInner;
use FmsOData\Spec\Errors\ODataErrorInnerError;
use FmsOData\Spec\Errors\RequestRef;

final class HttpClient
{
    /** @var \Closure(): string */
    private \Closure $tokenProvider;

    private ?\Closure $onUnauthorized;

    private ?int $timeoutMs;

    private TransportInterface $transport;

    /**
     * @param \Closure(): string|string $token
     * @param ?\Closure(): void $onUnauthorized
     */
    public function __construct(
        \Closure|string $token,
        TransportInterface $transport,
        ?\Closure $onUnauthorized = null,
        ?int $timeoutMs = null,
    ) {
        if ($token instanceof \Closure) {
            $this->tokenProvider = $token;
        } else {
            $this->tokenProvider = static fn (): string => $token;
        }
        $this->onUnauthorized = $onUnauthorized;
        $this->timeoutMs = $timeoutMs;
        $this->transport = $transport;
    }

    public function sendRequest(string $url, ?HttpRequestOptions $options = null): Response
    {
        $options ??= new HttpRequestOptions();

        $response = $this->execute($url, $options, false);

        if ($response->status === 401 && $this->onUnauthorized !== null) {
            ($this->onUnauthorized)();
            $response = $this->execute($url, $options, true);
            if ($response->status === 401) {
                throw $this->buildError($response, $options->method, $url);
            }
        }

        if (!$response->isSuccess()) {
            throw $this->buildError($response, $options->method, $url);
        }

        return $response;
    }

    public function sendJson(string $url, ?HttpRequestOptions $options = null): mixed
    {
        $options ??= new HttpRequestOptions();
        $response = $this->sendRequest($url, $options);

        if ($response->status === 204) {
            return null;
        }

        if ($response->body === '') {
            return null;
        }

        $isJsonExpected = $options->responseType === ResponseType::JSON;
        $isJsonContent = $response->isJson();

        if (!$isJsonContent && !$isJsonExpected) {
            return $response->body;
        }

        try {
            $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if (!$isJsonContent && !$isJsonExpected) {
                return $response->body;
            }
            throw new FMODataError(
                'Failed to parse JSON response: ' . $e->getMessage(),
                status: $response->status,
                request: new RequestRef($options->method, $url),
            );
        }

        return $decoded;
    }

    private function execute(string $url, HttpRequestOptions $options, bool $retried): Response
    {
        $headers = $options->headers;

        $token = ($this->tokenProvider)();
        if (!\is_string($token) || $token === '') {
            throw new FMODataError(
                'Token resolver produced an empty value',
                status: 0,
                request: new RequestRef($options->method, $url),
            );
        }

        $authValue = Auth::normalizeAuthToken($token);
        $headers['Authorization'] = $authValue;
        $headers['OData-Version'] ??= '4.0';
        $headers['OData-MaxVersion'] ??= '4.0';
        $headers['Accept'] ??= $options->responseType->defaultAccept();

        if ($options->body !== null && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        $request = new Request(
            method: $options->method,
            url: $url,
            headers: $headers,
            body: $options->body,
            timeoutMs: $this->timeoutMs,
        );

        try {
            return $this->transport->send($request);
        } catch (TransportException $e) {
            throw new FMODataError(
                'Transport error: ' . $e->getMessage(),
                status: 0,
                request: new RequestRef($options->method, $url),
            );
        }
    }

    private function buildError(Response $response, string $method, string $url): FMODataError
    {
        $requestRef = new RequestRef($method, $url);
        $body = $response->body;
        $code = null;
        $message = '';
        $odataError = null;

        $ctype = $response->contentType();
        $looksJson = ($ctype !== null && \str_contains(\strtolower($ctype), 'json'))
            || (\str_starts_with(\trim($body), '{') && \str_ends_with(\rtrim($body), '}'));
        $looksXml = ($ctype !== null && \str_contains(\strtolower($ctype), 'xml'))
            || \str_starts_with(\trim($body), '<?xml')
            || \str_contains($body, '<m:error');

        if ($looksJson && $body !== '') {
            try {
                $json = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
                if (\is_array($json) && isset($json['error']) && \is_array($json['error'])) {
                    $odataError = $this->parseODataError($json['error']);
                    $code = $odataError->error->code;
                    $message = $odataError->error->message;
                }
            } catch (\JsonException) {
                // Fall through to default message
            }
        } elseif ($looksXml && $body !== '') {
            if (\preg_match('/<m:code[^>]*>([^<]*)<\/m:code>/i', $body, $codeMatch)) {
                $code = $codeMatch[1];
            }
            if (\preg_match('/<m:message(?:\s[^>]*)?>([^<]*)<\/m:message>/i', $body, $msgMatch)) {
                $message = $msgMatch[1];
            }
        }

        if ($message === '') {
            $message = $response->reasonPhrase ?? 'HTTP ' . $response->status;
        }

        return $this->mapStatusError($response->status, $message, $code, $odataError, $requestRef);
    }

    /**
     * @param array<string|int, mixed> $error
     */
    private function parseODataError(array $error): ODataErrorBody
    {
        $code = \is_scalar($error['code'] ?? null) ? (string) $error['code'] : '';
        $rawMessage = $error['message'] ?? '';
        if (\is_array($rawMessage)) {
            $message = \is_scalar($rawMessage['value'] ?? null) ? (string) $rawMessage['value'] : '';
        } else {
            $message = \is_scalar($rawMessage) ? (string) $rawMessage : '';
        }

        $target = \is_scalar($error['target'] ?? null) ? (string) $error['target'] : null;

        $details = [];
        if (isset($error['details']) && \is_array($error['details'])) {
            foreach ($error['details'] as $detail) {
                if (!\is_array($detail)) {
                    continue;
                }
                $details[] = new ODataErrorDetail(
                    \is_scalar($detail['code'] ?? null) ? (string) $detail['code'] : '',
                    \is_scalar($detail['message'] ?? null) ? (string) $detail['message'] : '',
                    \is_scalar($detail['target'] ?? null) ? (string) $detail['target'] : null,
                );
            }
        }

        $innererror = null;
        if (isset($error['innererror']) && \is_array($error['innererror'])) {
            $inner = $error['innererror'];
            $innererror = new ODataErrorInnerError(
                \is_scalar($inner['type'] ?? null) ? (string) $inner['type'] : '',
                \is_scalar($inner['message'] ?? null) ? (string) $inner['message'] : '',
            );
        }

        return new ODataErrorBody(
            new ODataErrorInner($code, $message, $target, $details, $innererror),
        );
    }

    private function mapStatusError(
        int $status,
        string $message,
        ?string $code,
        ?ODataErrorBody $odataError,
        RequestRef $requestRef,
    ): FMODataError {
        return match ($status) {
            400 => new FMValidationError($message, $odataError, $requestRef),
            401 => new FMAuthError($message, $requestRef),
            404 => new FMNotFoundError($message, $requestRef),
            default => new FMODataError($message, $status, $code, $odataError, $requestRef),
        };
    }
}
