<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Typed API exception that preserves the existing abort(status, message)
 * contract while giving call sites a named, testable exception.
 *
 * Prefer ApiException::fail('msg') over bare abort(500, 'msg').
 */
class ApiException extends HttpException
{
    public static function fail(string $message = '', int $status = 500, ?\Throwable $previous = null): self
    {
        return new self($status, $message, $previous);
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    /**
     * Throw if $condition is truthy — replaces `if (!$x) abort(500, ...)`.
     */
    public static function abortIf(bool $condition, string $message = '', int $status = 500): void
    {
        if ($condition) {
            throw new self($status, $message);
        }
    }

    /**
     * Throw if $value is null — replaces `if (!$model) abort(...)`.
     */
    public static function abortIfNull(mixed $value, string $message = '', int $status = 500): void
    {
        if ($value === null) {
            throw new self($status, $message);
        }
    }
}
