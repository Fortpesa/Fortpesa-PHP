<?php
declare(strict_types=1);

namespace Fortpesa\Http;

use Fortpesa\Exception\NetworkException;

final class CurlHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
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

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new NetworkException('Could not reach Fortpesa: ' . curl_error($ch), curl_errno($ch));
        }

        return new HttpResponse((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $responseHeaders, (string) $raw);
    }
}
