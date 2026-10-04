<?php

/**
 * Tiny HTTP helper that works whether or not the curl extension is enabled —
 * some shared PHP hosts ship without it, which would otherwise fatal-error
 * with "Call to undefined function curl_init()".
 *
 * Unlike lastfm-dash's version this returns the status code and response
 * headers too, not just the body: Trakt reports pagination in headers
 * (X-Pagination-Page-Count), answers "nothing playing" with an empty 204,
 * and signals rate limiting with a 429 + Retry-After, all of which the
 * caller needs to tell apart from a plain failure.
 */
class Http
{
    /**
     * @param string|null $body raw request body (already JSON-encoded, etc.)
     * @return array{status:int, headers:array<string,string>, body:string}|null null on a transport failure
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): ?array
    {
        if (function_exists('curl_init')) {
            $responseHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $error) {
                return null;
            }

            return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $response];
        }

        if (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method'        => $method,
                    'timeout'       => $timeout,
                    'header'        => implode("\r\n", $headers),
                    'content'       => $body ?? '',
                    'ignore_errors' => true, // still return the body (and status) on 4xx/5xx
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                return null;
            }

            // $http_response_header is populated by file_get_contents() in this scope.
            $status = 0;
            $responseHeaders = [];
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1]; // last one wins if there were redirects
                    $responseHeaders = [];
                    continue;
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
            }

            return ['status' => $status, 'headers' => $responseHeaders, 'body' => $response];
        }

        return null;
    }

    public static function get(string $url, array $headers = [], int $timeout = 8): ?string
    {
        $response = self::request('GET', $url, $headers, null, $timeout);

        return ($response === null || $response['status'] >= 400) ? null : $response['body'];
    }
}
