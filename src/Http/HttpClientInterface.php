<?php
declare(strict_types=1);

namespace Fortpesa\Http;

interface HttpClientInterface
{
    /**
     * Perform one HTTP request and return the response, whatever its status code.
     *
     * @param array<string,string> $headers
     * @throws \Fortpesa\Exception\NetworkException when no HTTP response could be obtained
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse;
}
