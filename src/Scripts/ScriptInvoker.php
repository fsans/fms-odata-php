<?php

declare(strict_types=1);

namespace FmsOData\Scripts;

use FmsOData\Http\HttpClient;
use FmsOData\Http\HttpRequestOptions;
use FmsOData\Http\ResponseType;
use FmsOData\Spec\Errors\FMScriptError;
use FmsOData\Spec\Errors\RequestRef;
use FmsOData\Spec\Scripts\ScriptFmsidId;
use FmsOData\Spec\Scripts\ScriptNameId;
use FmsOData\Spec\Scripts\ScriptOptions;
use FmsOData\Spec\Scripts\ScriptResult;
use FmsOData\Spec\Scripts\Scripts;
use FmsOData\Url;

/**
 * Low-level handle for FileMaker script invocation.
 *
 * FMS exposes FileMaker scripts through the OData v4 Action mechanism at a
 * `Script.<ScriptName>` path suffix. Scripts can be invoked at three scopes:
 *
 *   POST /<db>/Script.<name>                       // database-level
 *   POST /<db>/<EntitySet>/Script.<name>           // entity-set context
 *   POST /<db>/<EntitySet>(<key>)/Script.<name>    // single-record context
 *
 * Starting with FileMaker Server 2026 (v26), scripts can also be invoked by
 * their immutable FMSID instead of the name:
 *
 *   POST /<db>/Script.FMSID:<id>
 *
 * The optional parameter is sent as `{"scriptParameterValue": "<string>"}`.
 * The response envelope is `{"scriptResult": "...", "scriptError": "0"}`;
 * a non-zero `scriptError` becomes an {@see FMScriptError}.
 *
 * Mirrors `src/scripts.ts` in fms-odata-js and `src/fms_odata/scripts.py`
 * in fms-odata-py. Reuses the spec-php {@see Scripts} facade for path
 * segment construction, request body building, and response parsing.
 *
 * @see https://github.com/fsans/fms-odata-spec/blob/main/docs/06-scripts.md
 */
final class ScriptInvoker
{
    private HttpClient $http;

    private string $baseUrl;

    private ?string $entitySet;

    private string|int|bool|null $key;

    public function __construct(
        HttpClient $http,
        string $baseUrl,
        ?string $entitySet = null,
        string|int|bool|null $key = null,
    ) {
        $this->http = $http;
        $this->baseUrl = $baseUrl;
        $this->entitySet = $entitySet;
        $this->key = $key;
    }

    /** Build the absolute URL for invoking `name` at this scope. */
    public function url(string $name): string
    {
        if ($name === '') {
            throw new \InvalidArgumentException('ScriptInvoker: script name is required');
        }

        return $this->urlForSegment(Scripts::pathSegment(new ScriptNameId($name)));
    }

    /** Build the absolute URL for invoking by FMSID at this scope. */
    public function urlById(int $fmsid): string
    {
        return $this->urlForSegment(Scripts::pathSegment(new ScriptFmsidId($fmsid)));
    }

    /**
     * Invoke the script by name. Returns a {@see ScriptResult} on success.
     *
     * @param string|int|float|array<string, mixed>|null $parameter
     */
    public function run(string $name, string|int|float|array|null $parameter = null): ScriptResult
    {
        return $this->runAtUrl($this->url($name), $parameter);
    }

    /**
     * Invoke the script by its immutable FMSID.
     *
     * Requires FileMaker Server 2026+ (v26). Use
     * `$client->hasFeature('scriptsByFMSID')` to check before calling.
     *
     * @param string|int|float|array<string, mixed>|null $parameter
     */
    public function runById(int $fmsid, string|int|float|array|null $parameter = null): ScriptResult
    {
        return $this->runAtUrl($this->urlById($fmsid), $parameter);
    }

    private function urlForSegment(string $scriptSegment): string
    {
        if ($this->entitySet === null) {
            return $this->baseUrl . '/' . $scriptSegment;
        }

        $setSegment = Url::encodePathSegment($this->entitySet);
        if ($this->key === null) {
            return $this->baseUrl . '/' . $setSegment . '/' . $scriptSegment;
        }

        return $this->baseUrl . '/' . $setSegment . '(' . Url::formatKey($this->key) . ')/' . $scriptSegment;
    }

    /**
     * @param string|int|float|array<string, mixed>|null $parameter
     */
    private function runAtUrl(string $url, string|int|float|array|null $parameter): ScriptResult
    {
        $options = new ScriptOptions(parameter: $parameter);
        $body = Scripts::requestBody($options);

        $headers = [];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $httpOptions = new HttpRequestOptions(
            method: 'POST',
            headers: $headers,
            body: $body,
            responseType: ResponseType::JSON,
        );

        $raw = $this->http->sendJson($url, $httpOptions);

        return self::parseAndRaise($raw, $url);
    }

    /**
     * Parse the script response envelope and raise {@see FMScriptError}
     * on a non-zero code.
     *
     * Handles both the nested (v26+) and flat (older FMS) envelope shapes,
     * delegating to the spec-php {@see Scripts::parseResponse()} for the
     * common path while preserving the TS/Python behaviour of raising on
     * non-zero errors.
     *
     * @param mixed $raw
     */
    public static function parseAndRaise(mixed $raw, string $url): ScriptResult
    {
        $result = Scripts::parseResponse($raw);

        if ($result->code !== 0) {
            throw new FMScriptError(
                'FileMaker script error ' . $result->code,
                scriptError: $result->code,
                scriptResult: $result->resultParameter,
                request: new RequestRef('POST', $url),
            );
        }

        return $result;
    }
}
