<?php

declare(strict_types=1);

namespace FmsOData\Http;

final class CurlTransport implements TransportInterface
{
    private bool $verifySsl;

    public function __construct(bool $verifySsl = true)
    {
        $this->verifySsl = $verifySsl;
    }

    public function send(Request $request): Response
    {
        $ch = \curl_init();
        if ($ch === false) {
            throw new TransportException('Failed to initialize cURL handle');
        }

        try {
            $this->configure($ch, $request);
            $responseHeaders = [];
            $raw = \curl_exec($ch);

            if ($raw === false) {
                $errno = \curl_errno($ch);
                $errmsg = \curl_error($ch);
                throw new TransportException(
                    'cURL error (' . $errno . '): ' . $errmsg,
                );
            }

            $status = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
            $headers = $this->parseHeaders($responseHeaders);
            $body = \is_string($raw) ? $raw : '';

            return new Response($status, $headers, $body);
        } catch (TransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException('Unexpected transport error: ' . $e->getMessage(), $e);
        } finally {
            \curl_close($ch);
        }
    }

    private function configure(\CurlHandle $ch, Request $request): void
    {
        \curl_setopt_array($ch, [
            \CURLOPT_URL => $request->url,
            \CURLOPT_CUSTOMREQUEST => $request->method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HEADER => false,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            \CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $ch, string $header) use (&$responseHeaders): int {
                $responseHeaders[] = $header;

                return \strlen($header);
            },
        ]);

        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if ($headerLines !== []) {
            \curl_setopt($ch, \CURLOPT_HTTPHEADER, $headerLines);
        }

        if ($request->body !== null) {
            \curl_setopt($ch, \CURLOPT_POSTFIELDS, $request->body);
        }

        if ($request->timeoutMs !== null && $request->timeoutMs > 0) {
            \curl_setopt($ch, \CURLOPT_TIMEOUT_MS, $request->timeoutMs);
        }
    }

    /**
     * @param list<string> $rawHeaders
     *
     * @return array<string, list<string>>
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $line) {
            $trimmed = \trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (\str_starts_with($trimmed, 'HTTP/')) {
                $headers = [];
                continue;
            }
            $pos = \strpos($trimmed, ':');
            if ($pos === false) {
                continue;
            }
            $name = \strtolower(\trim(\substr($trimmed, 0, $pos)));
            $value = \trim(\substr($trimmed, $pos + 1));
            $headers[$name][] = $value;
        }

        return $headers;
    }
}
