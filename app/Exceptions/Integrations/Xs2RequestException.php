<?php

namespace App\Exceptions\Integrations;

class Xs2RequestException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $body
     */
    public static function fromHttpResponse(int $status, ?array $body, ?string $url = null, string $prefix = 'XS2 request failed'): self
    {
        return new self(self::formatMessage($status, $body, $url, $prefix), $status, $body);
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $body
     */
    public static function formatMessage(int $status, ?array $body, ?string $url = null, string $prefix = 'XS2 request failed'): string
    {
        $message = $prefix.' with HTTP '.$status;
        if ($url !== null) {
            $message .= ' ('.self::sanitizeUrl($url).')';
        }

        $detail = data_get($body, 'message', data_get($body, 'error'));
        if (is_string($detail) && $detail !== '') {
            $message .= ': '.$detail;
        }

        if (is_array($body) && $body !== []) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && $encoded !== '' && $encoded !== '[]' && $encoded !== '{}') {
                $message .= ' Response: '.mb_substr($encoded, 0, 1500);
            }
        }

        return mb_substr($message, 0, 4000);
    }

    private static function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        return $scheme.'://'.$host.$path;
    }

    /**
     * Map an upstream XS2 HTTP status to a safe admin API response code.
     *
     * Upstream 401/403 must not be returned to the web app — it treats those as
     * provider session expiry and logs the user out.
     */
    public static function adminResponseStatus(?int $upstreamStatus, int $default = 422): int
    {
        if ($upstreamStatus === null) {
            return $default;
        }

        if ($upstreamStatus === 401 || $upstreamStatus === 403) {
            return 502;
        }

        return max(400, min(599, $upstreamStatus));
    }
}
