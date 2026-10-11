<?php
declare(strict_types=1);

namespace Fortpesa\Http;

final class HttpResponse
{
    /** @var int */
    public $status;
    /** @var array<string,string> lower-cased header names */
    public $headers;
    /** @var string */
    public $body;

    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? null;
    }
}
