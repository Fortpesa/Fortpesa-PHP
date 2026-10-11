<?php
declare(strict_types=1);

namespace Fortpesa\Exception;

/** The API answered with a non-2xx status. Branch on getErrorCode(), not on the message. */
class ApiException extends FortpesaException
{
    /** @var int */
    private $httpStatus;
    /** @var string|null */
    private $errorCode;
    /** @var array */
    private $errors;
    /** @var array|null */
    private $responseBody;

    public function __construct(string $message, int $httpStatus, ?string $errorCode = null, array $errors = [], ?array $responseBody = null)
    {
        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->errors = $errors;
        $this->responseBody = $responseBody;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Stable machine-readable code such as INSUFFICIENT_CREDIT (see Fortpesa\ErrorCode). Null for 422/429/5xx without a code. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** Field errors for 422 responses: ['amount' => ['...']]. */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    /** Builds the most specific exception for a failed response. */
    public static function fromResponse(int $status, ?array $body): self
    {
        $code = null;
        $message = null;
        $errors = [];

        if ($body !== null) {
            if (isset($body['error']) && is_array($body['error'])) {
                $code = isset($body['error']['code']) ? (string) $body['error']['code'] : null;
                $message = isset($body['error']['message']) ? (string) $body['error']['message'] : null;
            }
            if ($message === null && isset($body['message']) && is_string($body['message'])) {
                $message = $body['message'];
            }
            if (isset($body['errors']) && is_array($body['errors'])) {
                $errors = $body['errors'];
            }
        }

        if ($message === null) {
            $message = 'Fortpesa API request failed with HTTP ' . $status . '.';
        }

        switch ($status) {
            case 400:
            case 422:
                return new InvalidRequestException($message, $status, $code, $errors, $body);
            case 401:
                return new AuthenticationException($message, $status, $code, $errors, $body);
            case 402:
                return new InsufficientCreditException($message, $status, $code, $errors, $body);
            case 403:
                return new PermissionException($message, $status, $code, $errors, $body);
            case 404:
                return new NotFoundException($message, $status, $code, $errors, $body);
            case 429:
                return new RateLimitException($message, $status, $code, $errors, $body);
            case 503:
                return new ServiceUnavailableException($message, $status, $code, $errors, $body);
            default:
                return new self($message, $status, $code, $errors, $body);
        }
    }
}
