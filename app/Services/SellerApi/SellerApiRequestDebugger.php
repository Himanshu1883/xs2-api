<?php

namespace App\Services\SellerApi;

use Illuminate\Http\Client\Response;

class SellerApiRequestDebugger
{
    public function __construct(private readonly SellerApiDebugRecorder $recorder) {}

    /**
     * @param  array<string, scalar|null>  $payload
     * @param  array<string, scalar|null>  $requestHeaders
     */
    public function record(
        string $operation,
        string $method,
        string $url,
        array $requestHeaders,
        array $payload,
        Response $response,
    ): void {
        if (! $this->recorder->isEnabled()) {
            return;
        }

        $this->recorder->record([
            'operation' => $operation,
            'method' => strtoupper($method),
            'url' => $url,
            'request_headers' => $this->sanitizeHeaders($requestHeaders),
            'request_body' => $payload,
            'response_status' => $response->status(),
            'response_body' => $response->json() ?? $response->body(),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $payload
     * @param  array<string, scalar|null>  $requestHeaders
     */
    public function recordTransportFailure(
        string $operation,
        string $method,
        string $url,
        array $requestHeaders,
        array $payload,
        ?Response $response,
        string $message,
    ): void {
        if (! $this->recorder->isEnabled()) {
            return;
        }

        $this->recorder->record([
            'operation' => $operation,
            'method' => strtoupper($method),
            'url' => $url,
            'request_headers' => $this->sanitizeHeaders($requestHeaders),
            'request_body' => $payload,
            'response_status' => $response?->status(),
            'response_body' => $response !== null ? ($response->json() ?? $response->body()) : null,
            'error' => $message,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $headers
     * @return array<string, string>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];
        foreach ($headers as $name => $value) {
            $headerName = (string) $name;
            $headerValue = (string) $value;
            $normalized = strtolower($headerName);
            if (in_array($normalized, ['apikey', 'authorization', 'x-api-key'], true)
                || str_contains($normalized, 'api')
                    && str_contains($normalized, 'key')) {
                $sanitized[$headerName] = $this->maskSecret($headerValue);

                continue;
            }

            $sanitized[$headerName] = $headerValue;
        }

        return $sanitized;
    }

    private function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $value, $matches)) {
            return 'Bearer '.$this->maskSecret($matches[1]);
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 2).str_repeat('*', max(4, $length - 4)).substr($value, -2);
    }
}
